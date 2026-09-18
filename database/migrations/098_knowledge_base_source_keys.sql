ALTER TABLE knowledge_folders
    ADD COLUMN source_key VARCHAR(190) NULL AFTER id;

ALTER TABLE knowledge_folders
    ADD UNIQUE KEY uq_knowledge_folders_source_key (source_key);

ALTER TABLE knowledge_documents
    ADD COLUMN source_key VARCHAR(190) NULL AFTER id;

ALTER TABLE knowledge_documents
    ADD UNIQUE KEY uq_knowledge_documents_source_key (source_key);
