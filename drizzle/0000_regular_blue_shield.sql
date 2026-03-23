CREATE TABLE `recordings` (
	`id` text PRIMARY KEY NOT NULL,
	`meeting_id` text NOT NULL,
	`meeting_name` text,
	`start_time` integer,
	`end_time` integer,
	`video_url` text NOT NULL,
	`status` text DEFAULT 'pending',
	`error` text,
	`created_at` integer DEFAULT (unixepoch()),
	`updated_at` integer DEFAULT (unixepoch())
);
--> statement-breakpoint
CREATE TABLE `transcripts` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`recording_id` text NOT NULL,
	`text` text NOT NULL,
	`vtt` text,
	`language` text,
	`duration_seconds` real,
	`model` text DEFAULT 'base',
	`created_at` integer DEFAULT (unixepoch()),
	FOREIGN KEY (`recording_id`) REFERENCES `recordings`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `transcripts_recording_id_unique` ON `transcripts` (`recording_id`);