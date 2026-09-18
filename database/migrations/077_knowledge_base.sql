CREATE TABLE IF NOT EXISTS knowledge_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_folders_parent FOREIGN KEY (parent_id) REFERENCES knowledge_folders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_knowledge_folders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_folders_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_knowledge_folders_parent_order (parent_id, sort_order, name),
    INDEX idx_knowledge_folders_archived (archived_at)
);

CREATE TABLE IF NOT EXISTS knowledge_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id BIGINT UNSIGNED NULL,
    title VARCHAR(240) NOT NULL,
    summary VARCHAR(600) NOT NULL DEFAULT '',
    body_html MEDIUMTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    current_version INT NOT NULL DEFAULT 0,
    draft_folder_id BIGINT UNSIGNED NULL,
    draft_title VARCHAR(240) NOT NULL,
    draft_summary VARCHAR(600) NOT NULL DEFAULT '',
    draft_body_html MEDIUMTEXT NOT NULL,
    draft_is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    draft_updated_at DATETIME NULL,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_documents_folder FOREIGN KEY (folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_documents_draft_folder FOREIGN KEY (draft_folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_documents_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_documents_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_knowledge_documents_folder_status (folder_id, status, is_pinned, sort_order),
    INDEX idx_knowledge_documents_updated (updated_at),
    INDEX idx_knowledge_documents_published (status, published_at)
);

CREATE TABLE IF NOT EXISTS knowledge_document_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    version_no INT NOT NULL,
    folder_id BIGINT UNSIGNED NULL,
    title VARCHAR(240) NOT NULL,
    summary VARCHAR(600) NOT NULL DEFAULT '',
    body_html MEDIUMTEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_revisions_document FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_knowledge_revisions_folder FOREIGN KEY (folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_revisions_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_knowledge_revision_version (document_id, version_no),
    INDEX idx_knowledge_revisions_document (document_id, version_no)
);

INSERT IGNORE INTO knowledge_folders (id, parent_id, name, sort_order) VALUES
    (1, NULL, 'Лемма', 10),
    (2, NULL, 'Атлас', 20),
    (3, 1, 'Начало работы', 10),
    (4, 1, 'Задачи', 20),
    (5, 1, 'Проекты', 30),
    (6, 1, 'Время и отчёты', 40),
    (7, 2, 'Навигация', 10),
    (8, 2, 'Загрузка моделей', 20),
    (9, 2, 'Структура и свойства', 30),
    (10, 2, 'Виды и сечения', 40);

INSERT IGNORE INTO knowledge_documents (
    id, folder_id, title, summary, body_html, status, is_pinned, sort_order, current_version,
    draft_folder_id, draft_title, draft_summary, draft_body_html, draft_is_pinned,
    draft_updated_at, published_at
) VALUES
    (1, 1, 'Руководство по Лемме', 'Задачи, проекты, время, согласования и отчёты.',
     '<h2>Начало работы</h2><p>После входа Лемма открывает рабочий экран вашей роли. В разделе «Мой день» собраны ближайшие задачи, проверки и действия, которые требуют внимания сейчас.</p><h2>Задачи</h2><p>Новая задача содержит результат, исполнителя, срок, плановые часы и проверяющего. Исполнитель принимает задачу, ведёт работу и отправляет результат на проверку. Закрытие выполняется через проверку, а не ручной сменой статуса.</p><blockquote>Срок, исполнитель и ожидаемый результат должны быть понятны до начала работы.</blockquote><h2>Проекты</h2><p>Карточка проекта объединяет паспорт, задачи, календарь, историю, команду и модели. Рабочая информация находится сверху, служебные настройки и справочники — в нижних раскрываемых разделах.</p><h2>Время и отчёты</h2><p>Фактическое время списывается на конкретные задачи. Руководитель принимает время в отдельном управленческом контуре, а отчёты показывают личный план, личный факт и общий результат задачи раздельно.</p><h2>Проверка результата</h2><ol><li>Исполнитель отправляет работу на проверку.</li><li>Проверяющий принимает результат или возвращает его с замечанием.</li><li>Все решения и изменения срока сохраняются в журнале задачи.</li></ol>',
     'published', 1, 10, 1, 1, 'Руководство по Лемме', 'Задачи, проекты, время, согласования и отчёты.',
     '<h2>Начало работы</h2><p>После входа Лемма открывает рабочий экран вашей роли. В разделе «Мой день» собраны ближайшие задачи, проверки и действия, которые требуют внимания сейчас.</p><h2>Задачи</h2><p>Новая задача содержит результат, исполнителя, срок, плановые часы и проверяющего. Исполнитель принимает задачу, ведёт работу и отправляет результат на проверку. Закрытие выполняется через проверку, а не ручной сменой статуса.</p><blockquote>Срок, исполнитель и ожидаемый результат должны быть понятны до начала работы.</blockquote><h2>Проекты</h2><p>Карточка проекта объединяет паспорт, задачи, календарь, историю, команду и модели. Рабочая информация находится сверху, служебные настройки и справочники — в нижних раскрываемых разделах.</p><h2>Время и отчёты</h2><p>Фактическое время списывается на конкретные задачи. Руководитель принимает время в отдельном управленческом контуре, а отчёты показывают личный план, личный факт и общий результат задачи раздельно.</p><h2>Проверка результата</h2><ol><li>Исполнитель отправляет работу на проверку.</li><li>Проверяющий принимает результат или возвращает его с замечанием.</li><li>Все решения и изменения срока сохраняются в журнале задачи.</li></ol>',
     1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (2, 2, 'Руководство по Атласу', 'Навигация, модели, структура, свойства, виды и сечения.',
     '<h2>Открытие моделей</h2><p>Атлас открывается из главной страницы, бокового меню или карточки проекта. Проект может содержать IFC, IFCZIP и готовые FRAG-модели. Папка проекта сканируется автоматически.</p><h2>Навигация</h2><p>Мышь или жесты вращают, приближают и перемещают модель. Режим ходьбы работает постоянно. Навигационный куб возвращает стандартные виды сверху, спереди, слева и в изометрию.</p><h2>Структура модели</h2><p>Дерево показывает модели, категории и элементы. Выбор категории подсвечивает все входящие элементы. Выбор отдельного элемента автоматически приближает камеру и открывает его свойства.</p><h2>Виды и сечения</h2><p>Верхняя панель содержит стандартные 2D-виды, плоскость подрезки и подрезку кубиком. Состояние сцены можно сохранить в задачу вместе с камерой и выбранным элементом.</p><h2>Работа на телефоне</h2><p>На мобильном устройстве основные команды остаются в верхней панели, а структура и свойства открываются как компактные рабочие панели. Для точного выбора элемента увеличьте нужный фрагмент модели.</p><blockquote>Если модель обновилась, используйте команду «Обновить папку» в карточке проекта, чтобы сбросить старый FRAG-кеш.</blockquote>',
     'published', 1, 20, 1, 2, 'Руководство по Атласу', 'Навигация, модели, структура, свойства, виды и сечения.',
     '<h2>Открытие моделей</h2><p>Атлас открывается из главной страницы, бокового меню или карточки проекта. Проект может содержать IFC, IFCZIP и готовые FRAG-модели. Папка проекта сканируется автоматически.</p><h2>Навигация</h2><p>Мышь или жесты вращают, приближают и перемещают модель. Режим ходьбы работает постоянно. Навигационный куб возвращает стандартные виды сверху, спереди, слева и в изометрию.</p><h2>Структура модели</h2><p>Дерево показывает модели, категории и элементы. Выбор категории подсвечивает все входящие элементы. Выбор отдельного элемента автоматически приближает камеру и открывает его свойства.</p><h2>Виды и сечения</h2><p>Верхняя панель содержит стандартные 2D-виды, плоскость подрезки и подрезку кубиком. Состояние сцены можно сохранить в задачу вместе с камерой и выбранным элементом.</p><h2>Работа на телефоне</h2><p>На мобильном устройстве основные команды остаются в верхней панели, а структура и свойства открываются как компактные рабочие панели. Для точного выбора элемента увеличьте нужный фрагмент модели.</p><blockquote>Если модель обновилась, используйте команду «Обновить папку» в карточке проекта, чтобы сбросить старый FRAG-кеш.</blockquote>',
     1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT IGNORE INTO knowledge_document_revisions (
    document_id, version_no, folder_id, title, summary, body_html, is_pinned
)
SELECT id, 1, folder_id, title, summary, body_html, is_pinned
FROM knowledge_documents
WHERE id IN (1, 2) AND current_version = 1;
