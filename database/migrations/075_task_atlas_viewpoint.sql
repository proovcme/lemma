ALTER TABLE task_atlas_refs
    ADD COLUMN viewpoint_json JSON NULL;

ALTER TABLE task_atlas_refs
    ADD COLUMN overlay_json JSON NULL;
