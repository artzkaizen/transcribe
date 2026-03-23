<?php
// This file is part of Moodle - https://moodle.org/
// ... (License and header info as before) ...

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$id = optional_param('id', 0, PARAM_INT); // Get course module ID

if ($id) {
  $cm = get_coursemodule_from_id('dailylogs', $id, 0, false, MUST_EXIST);
  $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
  $moduleinstance = $DB->get_record('dailylogs', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
  redirect(new moodle_url('/'));
}

// Ensure the user is logged in and has access to the course
require_login($course, true, $cm);

$modulecontext = context_module::instance($cm->id);

// Check capability
require_capability('mod/dailylogs:view', $modulecontext);

// Get the current group ID from the dropdown
$groupid = optional_param('group', 0, PARAM_INT);

// User data
$user_roles = get_user_roles($modulecontext, $USER->id);
$user_groups = groups_get_all_groups($course->id, $USER->id) ?: [];
$groupmode = groups_get_activity_groupmode($cm);
$accessallgroups = has_capability('moodle/site:accessallgroups', $modulecontext);

// Initialize Moodle cache (TTL is defined in db/caches.php)
$cache = cache::make('mod_dailylogs', 'records');

// Create cache key based on course, group, and user access
$isMitarbeiterOrHigher = has_capability('mod/dailylogs:viewallgroups', $modulecontext) || 
                         has_capability('moodle/site:accessallgroups', $modulecontext);

// Fetch groups based on user's role
if ($isMitarbeiterOrHigher) {
    $allgroups = groups_get_all_groups($course->id);
} else {
    if ($groupmode == VISIBLEGROUPS) {
        $allgroups = groups_get_all_groups($course->id);
    } else {
        $allgroups = groups_get_all_groups($course->id, $USER->id);
    }
}

// Create user object (cache this separately as it changes less frequently)
$user_cache_key = 'user_' . $USER->id . '_' . $cm->id;
$user_obj = $cache->get($user_cache_key);

if ($user_obj === false) {
    $user_obj = new stdClass();
    $user_obj->id = $USER->id;
    $user_obj->roles = array_values(
        array_map(
            fn ($role) => $DB->get_record('role', ['id' => $role->roleid])->shortname,
            $user_roles,
        ),
    );
    $user_obj->groups = !empty($user_groups)
        ? array_map(fn ($group) => $group->name, $user_groups)
        : [];
    $user_obj->groupmode = $groupmode;
    $user_obj->accessallgroups = $accessallgroups;
    $user_obj->usergroups = !empty($user_groups)
        ? array_map(
            function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                ];
            },
            $user_groups,
        )
        : [];
    
    // Cache user object
    $cache->set($user_cache_key, $user_obj);
}

// Get course modules (cache this as it rarely changes)
$modules_cache_key = 'modules_' . $cm->course;
$course_modules = $cache->get($modules_cache_key);

if ($course_modules === false) {
    $course_modules = $DB->get_records('course_modules', ['course' => $cm->course]);
    $cache->set($modules_cache_key, $course_modules);
}

// Build cache key for data records based on course, group, and user access
$cache_key_parts = [
    'course' => $cm->course,
    'group' => $groupid,
    'groupmode' => $groupmode,
    'accessall' => $accessallgroups ? 1 : 0,
    'userid' => $accessallgroups ? 0 : $USER->id, // Only include user ID if not admin
];
$data_cache_key = 'data_records_' . md5(serialize($cache_key_parts));

// Try to get cached data records
$course_data_records = $cache->get($data_cache_key);

if ($course_data_records === false) {
    // Base SQL query without the group filtering
    $sql_data_base = "
    SELECT
        dr.id AS record_id,
        d.id AS data_id,
        cm.id AS course_module_id,
        MAX(CASE WHEN df.name = 'Datum' THEN dc.content END) AS Datum,
        MAX(CASE WHEN df.name = 'Lernfeld' THEN dc.content END) AS Lernfeld,
        MAX(CASE WHEN df.name = 'Tagesinhalte' THEN dc.content END) AS Tagesinhalte,
        MAX(f_tafelbild.file_path) AS Tafelbild,
        MAX(f_praesentation.file_path) AS Präsentation,
        g.id AS group_id,
        g.name AS group_name,
        CASE
            WHEN g.id IS NOT NULL THEN g.name
            ELSE '" . get_string('allparticipants') . "'
        END AS group_display_name
    FROM
        {course_modules} cm
    JOIN
        {modules} m ON m.id = cm.module
    JOIN
        {data} d ON d.id = cm.instance
    JOIN
        {data_records} dr ON dr.dataid = d.id
    JOIN
        {data_content} dc ON dc.recordid = dr.id
    JOIN
        {data_fields} df ON df.id = dc.fieldid
    LEFT JOIN (
        SELECT dc.recordid, CONCAT('/', f.contextid, '/', f.component, '/', f.filearea, '/', f.itemid, f.filepath, f.filename) AS file_path
        FROM {files} f
        JOIN {data_content} dc ON dc.id = f.itemid
        JOIN {data_fields} df ON df.id = dc.fieldid
        WHERE f.component = 'mod_data' AND f.filearea = 'content' AND df.name = 'Tafelbild'
    ) AS f_tafelbild ON f_tafelbild.recordid = dr.id
    LEFT JOIN (
        SELECT dc.recordid, CONCAT('/', f.contextid, '/', f.component, '/', f.filearea, '/', f.itemid, f.filepath, f.filename) AS file_path
        FROM {files} f
        JOIN {data_content} dc ON dc.id = f.itemid
        JOIN {data_fields} df ON df.id = dc.fieldid
        WHERE f.component = 'mod_data' AND f.filearea = 'content' AND df.name = 'Präsentation'
    ) AS f_praesentation ON f_praesentation.recordid = dr.id
    LEFT JOIN {groups} g ON g.id = dr.groupid
    WHERE
        cm.course = :courseid
    ";

    // Parameters for the SQL query
    $sql_params = ['courseid' => $cm->course];

    // Add group filtering conditions based on user access and selected group
    $groupwhere = '';

    if ($groupmode == SEPARATEGROUPS && !$accessallgroups) {
        // User can only see their own groups
        $usergroups = groups_get_all_groups($course->id, $USER->id);
        if (!empty($usergroups)) {
            $groupids = array_keys($usergroups);
            list($insql, $inparams) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
            $groupwhere .= " AND (dr.groupid $insql OR dr.groupid IS NULL)";
            $sql_params = array_merge($sql_params, $inparams);
        } else {
            // No groups, only show records without a group
            $groupwhere .= " AND dr.groupid IS NULL";
        }
    }

    // If a specific group is selected from dropdown
    if ($groupid > 0) {
        // Verify user has access to this group in SEPARATEGROUPS mode
        if ($groupmode == SEPARATEGROUPS && !$accessallgroups) {
            $useraccessgroups = array_keys(groups_get_all_groups($course->id, $USER->id));
            if (!in_array($groupid, $useraccessgroups)) {
                // User doesn't have access to this group
            }
        }
        
        $groupwhere .= " AND dr.groupid = :selectedgroupid";
        $sql_params['selectedgroupid'] = $groupid;
    }

    // Complete SQL query
    $final_sql = $sql_data_base . $groupwhere . " 
    GROUP BY
        dr.id, g.id, g.name
    ORDER BY
        MAX(CASE WHEN df.name = 'Datum' THEN dc.content END) DESC
    ";

    // Get records with appropriate group access control
    $course_data_records = $DB->get_records_sql($final_sql, $sql_params);
    
    // Cache the results
    $cache->set($data_cache_key, $course_data_records);
}

// SQL for BBB records with similar group filtering (cache this too)
$bbb_cache_key = 'bbb_records_' . md5(serialize($cache_key_parts));
$bbb_records = $cache->get($bbb_cache_key);

if ($bbb_records === false) {
    $sql_bbb_base = "
    SELECT r.id, r.recordingid, r.status, r.groupid, r.timecreated, g.name as groupname
    FROM {bigbluebuttonbn_recordings} r
    LEFT JOIN {groups} g ON g.id = r.groupid
    WHERE r.courseid = :courseid
    AND r.status = 2
    ";

    $bbb_where = '';
    if ($groupmode == SEPARATEGROUPS && !$accessallgroups) {
        // User can only see their own groups
        $usergroups = groups_get_all_groups($course->id, $USER->id);
        if (!empty($usergroups)) {
            $groupids = array_keys($usergroups);
            list($insql, $inparams) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'bgrp');
            $bbb_where .= " AND (r.groupid $insql OR r.groupid IS NULL)";
            $bbb_params = array_merge(['courseid' => $cm->course], $inparams);
        } else {
            // No groups, only show records without a group
            $bbb_where .= " AND r.groupid IS NULL";
            $bbb_params = ['courseid' => $cm->course];
        }
    } else {
        $bbb_params = ['courseid' => $cm->course];
    }

    // If a specific group is selected from dropdown
    if ($groupid > 0) {
        $bbb_where .= " AND r.groupid = :selectedgroupid";
        $bbb_params['selectedgroupid'] = $groupid;
    }

    $sql_bbb = $sql_bbb_base . $bbb_where;
    $bbb_records = $DB->get_records_sql($sql_bbb, $bbb_params);
    
    // Cache BBB records
    $cache->set($bbb_cache_key, $bbb_records);
}

// Process records
$processed_records_cache_key = 'processed_records_' . md5(serialize($cache_key_parts));
$processed_records = $cache->get($processed_records_cache_key);

if ($processed_records === false) {
    $processed_records = [];
    $matched_bbb_ids = [];

    // First pass: Build record objects from daily logs, match BBB where possible
    foreach ($course_data_records as $record) {
        $record_obj = new stdClass();
        $record_obj->id = $record->record_id;
        $record_obj->datum = $record->datum;
        $record_obj->group_id = $record->group_id;
        $record_obj->lernfeld = $record->lernfeld;
        $record_obj->tagesinhalte = $record->tagesinhalte;
        $record_obj->tafelbild = $record->tafelbild;
        $record_obj->praesentation = $record->praesentation;
        $record_obj->präsentation = $record->präsentation;
        $record_obj->group_name = $record->group_name;
        $record_obj->group_display_name = $record->group_display_name;
        $record_obj->recording_ids = [];
        $record_obj->standalone = false;

        if (!empty($bbb_records)) {
            foreach ($bbb_records as $bbb_record) {
                $bbb_date = date('Y-m-d', $bbb_record->timecreated);
                $record_date = date('Y-m-d', $record->datum);

                if ($bbb_date === $record_date && $bbb_record->groupid === $record->group_id) {
                    $record_obj->recording_ids[] = $bbb_record->recordingid;
                    $matched_bbb_ids[] = $bbb_record->id;
                }
            }
        }

        $processed_records[] = $record_obj;
    }

    // Second pass: Add unmatched BBB recordings as standalone entries
    if (!empty($bbb_records)) {
        foreach ($bbb_records as $bbb_record) {
            if (in_array($bbb_record->id, $matched_bbb_ids)) {
                continue;
            }

            $record_obj = new stdClass();
            $record_obj->id = 'bbb_' . $bbb_record->id;
            $record_obj->datum = date('Y-m-d', $bbb_record->timecreated);
            $record_obj->group_id = $bbb_record->groupid;
            $record_obj->lernfeld = null;
            $record_obj->tagesinhalte = null;
            $record_obj->tafelbild = null;
            $record_obj->praesentation = null;
            $record_obj->präsentation = null;
            $record_obj->group_name = $bbb_record->groupname ?? null;
            $record_obj->group_display_name = $bbb_record->groupname ?? get_string('allparticipants');
            $record_obj->recording_ids = [$bbb_record->recordingid];
            $record_obj->standalone = true;

            $processed_records[] = $record_obj;
        }
    }

    // Sort all records by date
    usort($processed_records, function ($a, $b) {
        return strtotime($b->datum) - strtotime($a->datum);
    });
    
    // Cache processed records (TTL is defined in cache definition)
    $cache->set($processed_records_cache_key, $processed_records);
}

// Encode for JavaScript
$records_json = json_encode(
    $processed_records,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

$user_main = json_encode($user_obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$modules = json_encode($course_modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$cid = json_encode($id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$allgroups_json = json_encode($allgroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Set page URL, context, title, and CSS
$PAGE->set_url(new moodle_url('/mod/dailylogs/view.php', ['id' => $cm->id]));
$PAGE->requires->css('/mod/dailylogs/app/assets/index.css');
$PAGE->set_context($modulecontext);
$PAGE->set_title('Course Overview');

// Get current group id from optional param
$selectedgroup = optional_param('group', 0, PARAM_INT);

// Setup group selector if needed
$groupselect = '';
if ($groupmode) {
    $groupselect = groups_print_activity_menu($cm, $PAGE->url, true);
}

// Create an array of options for the dropdown
$groupoptions = [0 => get_string('allparticipants')];
foreach ($allgroups as $group) {
    $groupoptions[$group->id] = format_string($group->name);
}

// Start outputting the page
echo $OUTPUT->header();

//Output group selector
if ($groupselect) {
    echo html_writer::div($groupselect, 'group-selector d-print-none mb-3');
}

if (count($allgroups) > 1) {
    // Create a form to contain the dropdown
    echo '<form action="' . $PAGE->url . '" method="get">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    // Output the dropdown using Moodle's select element
    echo '<label style="margin-right: 10px;" for="group">' . get_string('select_group', 'mod_dailylogs') . '</label>';
    echo html_writer::select($groupoptions, 'group', $selectedgroup, "", ['onchange' => 'this.form.submit()']);
    echo '</form>';
}



// Output the root div for React
// echo '<div id="root"></div>';

echo $OUTPUT->footer();


?>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const backToCourse = document.querySelector(".course-content-header");
        const mainContent = document.querySelector(".main-content");
        if (mainContent) {
            mainContent.style.border = "1px solid hsl(var(--border-color))";
            mainContent.style.borderRadius = "3px";
            mainContent.style.position = "relative";
            mainContent.style.overflow = "hidden";
        }

        window.sk = "<?php echo sesskey(); ?>";
        const allgroups = <?php echo $allgroups_json; ?>;
        const records = <?php echo $records_json; ?>;
        const user_obj = <?php echo $user_main; ?>;
        const courseId = <?php echo $cid; ?>;
        const currentGroup = <?php echo $groupid; ?>;
        const module = Object.values(<?php echo $modules; ?>).find(
            (item) => item.idnumber === "menu_dailylogs_db"
        );

        window.course = {
            user: user_obj,
            records,
            module,
            courseId,
            currentGroup,
            groupMode: <?php echo $groupmode; ?>,
            allgroups,
        };
    });

    window.onload = () => {
        const script = document.createElement("script");
        script.type = "module";
        script.src = "<?php echo new moodle_url('/mod/dailylogs/app/assets/index.js'); ?>";
        document.body.appendChild(script);
    };
</script>



