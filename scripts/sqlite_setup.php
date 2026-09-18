<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\GgeKnowledgeCorpusService;
use App\Services\RoleService;
use App\Services\SbcCatalogService;

require_once dirname(__DIR__) . '/app/bootstrap.php';

function sqlite_quote_identifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

/**
 * @return array<string, array{table: string, columns: array<int, string>, unique: bool}>
 */
function sqlite_required_indexes(): array
{
    return [
        'idx_users_role' => ['table' => 'users', 'columns' => ['role'], 'unique' => false],
        'idx_users_department' => ['table' => 'users', 'columns' => ['department'], 'unique' => false],
        'idx_users_manager' => ['table' => 'users', 'columns' => ['manager_id'], 'unique' => false],
        'idx_users_position' => ['table' => 'users', 'columns' => ['position_id'], 'unique' => false],
        'uq_positions_role_key' => ['table' => 'positions', 'columns' => ['role_key'], 'unique' => true],
        'idx_position_access_updated_by' => ['table' => 'position_access_permissions', 'columns' => ['updated_by'], 'unique' => false],
        'idx_role_access_updated_by' => ['table' => 'role_access_permissions', 'columns' => ['updated_by'], 'unique' => false],
        'idx_projects_stage' => ['table' => 'projects', 'columns' => ['stage'], 'unique' => false],
        'idx_projects_status' => ['table' => 'projects', 'columns' => ['status'], 'unique' => false],
        'idx_projects_kind_status' => ['table' => 'projects', 'columns' => ['kind', 'status'], 'unique' => false],
        'idx_projects_gip' => ['table' => 'projects', 'columns' => ['gip_user_id'], 'unique' => false],
        'idx_projects_rp' => ['table' => 'projects', 'columns' => ['rp_user_id'], 'unique' => false],
        'idx_calculator_portfolio_status_finish' => ['table' => 'calculator_portfolio_entries', 'columns' => ['status', 'finish_date'], 'unique' => false],
        'idx_calculator_portfolio_creator' => ['table' => 'calculator_portfolio_entries', 'columns' => ['created_by'], 'unique' => false],
        'uq_tasks_project_msp_uid' => ['table' => 'tasks', 'columns' => ['project_id', 'msp_task_uid'], 'unique' => true],
        'idx_tasks_project_msp_task_id' => ['table' => 'tasks', 'columns' => ['project_id', 'msp_task_id'], 'unique' => false],
        'idx_personal_notes_author_status' => ['table' => 'personal_notes', 'columns' => ['author_id', 'status', 'pinned', 'updated_at'], 'unique' => false],
        'idx_personal_notes_project' => ['table' => 'personal_notes', 'columns' => ['project_id'], 'unique' => false],
        'idx_tasks_status_end' => ['table' => 'tasks', 'columns' => ['status', 'date_end'], 'unique' => false],
        'idx_tasks_assignee_status' => ['table' => 'tasks', 'columns' => ['assignee_id', 'status'], 'unique' => false],
        'idx_tasks_author_status' => ['table' => 'tasks', 'columns' => ['author_id', 'status'], 'unique' => false],
        'idx_tasks_reviewer_status' => ['table' => 'tasks', 'columns' => ['reviewer_id', 'status'], 'unique' => false],
        'idx_tasks_parent' => ['table' => 'tasks', 'columns' => ['parent_id'], 'unique' => false],
        'idx_tasks_discipline' => ['table' => 'tasks', 'columns' => ['discipline'], 'unique' => false],
        'idx_tasks_section' => ['table' => 'tasks', 'columns' => ['section'], 'unique' => false],
        'idx_tasks_type_project' => ['table' => 'tasks', 'columns' => ['task_type', 'project_id'], 'unique' => false],
        'idx_tasks_project_section' => ['table' => 'tasks', 'columns' => ['project_section_id'], 'unique' => false],
        'idx_tasks_approval_stage' => ['table' => 'tasks', 'columns' => ['approval_stage'], 'unique' => false],
        'idx_tasks_pp_code' => ['table' => 'tasks', 'columns' => ['pp_code_id'], 'unique' => false],
        'idx_tasks_btp_code' => ['table' => 'tasks', 'columns' => ['btp_code_id'], 'unique' => false],
        'idx_tasks_cost_group_code' => ['table' => 'tasks', 'columns' => ['cost_group_code'], 'unique' => false],
        'idx_task_participants_task' => ['table' => 'task_participants', 'columns' => ['task_id'], 'unique' => false],
        'idx_task_participants_user_role' => ['table' => 'task_participants', 'columns' => ['user_id', 'role'], 'unique' => false],
        'idx_task_atlas_refs_task' => ['table' => 'task_atlas_refs', 'columns' => ['task_id'], 'unique' => false],
        'idx_task_atlas_refs_project' => ['table' => 'task_atlas_refs', 'columns' => ['project_id'], 'unique' => false],
        'uq_task_issuances_task_number' => ['table' => 'task_issuances', 'columns' => ['task_id', 'issue_number'], 'unique' => true],
        'idx_task_issuances_task_status' => ['table' => 'task_issuances', 'columns' => ['task_id', 'status', 'issued_at'], 'unique' => false],
        'idx_document_revisions_project' => ['table' => 'document_revisions', 'columns' => ['project_id', 'revision_no'], 'unique' => false],
        'idx_document_revisions_issuance' => ['table' => 'document_revisions', 'columns' => ['issuance_id'], 'unique' => false],
        'idx_task_approvals_task_created' => ['table' => 'task_approvals', 'columns' => ['task_id', 'created_at'], 'unique' => false],
        'idx_task_approvals_stage_decision' => ['table' => 'task_approvals', 'columns' => ['stage', 'decision'], 'unique' => false],
        'idx_schedule_project_date' => ['table' => 'project_schedule', 'columns' => ['project_id', 'rd_date_plan'], 'unique' => false],
        'idx_schedule_status' => ['table' => 'project_schedule', 'columns' => ['rd_readiness', 'rd_date_plan'], 'unique' => false],
        'uq_schedule_project_task' => ['table' => 'project_schedule', 'columns' => ['project_id', 'task_id'], 'unique' => true],
        'idx_schedule_task' => ['table' => 'project_schedule', 'columns' => ['task_id'], 'unique' => false],
        'idx_sections_project' => ['table' => 'project_sections', 'columns' => ['project_id'], 'unique' => false],
        'idx_sections_task' => ['table' => 'project_sections', 'columns' => ['task_id'], 'unique' => false],
        'idx_sections_code' => ['table' => 'project_sections', 'columns' => ['code'], 'unique' => false],
        'idx_sections_sbc_item' => ['table' => 'project_sections', 'columns' => ['sbc_item_id'], 'unique' => false],
        'idx_project_sections_reviewer' => ['table' => 'project_sections', 'columns' => ['reviewer_id'], 'unique' => false],
        'idx_project_sections_stage' => ['table' => 'project_sections', 'columns' => ['project_id', 'stage_id', 'work_kind', 'sort_order'], 'unique' => false],
        'idx_project_section_assignments_role' => ['table' => 'project_section_assignments', 'columns' => ['project_section_id', 'assignment_role', 'sort_order'], 'unique' => false],
        'idx_issues_project_status' => ['table' => 'project_issues', 'columns' => ['project_id', 'status'], 'unique' => false],
        'idx_issues_blocking_task' => ['table' => 'project_issues', 'columns' => ['blocking_task_id'], 'unique' => false],
        'idx_issues_status' => ['table' => 'project_issues', 'columns' => ['status'], 'unique' => false],
        'idx_issues_assignee_status_project_date' => ['table' => 'project_issues', 'columns' => ['assignee_id', 'status', 'project_id', 'date_raised'], 'unique' => false],
        'idx_data_registry_project_status' => ['table' => 'project_data_registry', 'columns' => ['project_id', 'status'], 'unique' => false],
        'idx_data_registry_status_date' => ['table' => 'project_data_registry', 'columns' => ['status', 'date_received_plan'], 'unique' => false],
        'uq_counterparties_identity' => ['table' => 'counterparties', 'columns' => ['company', 'role', 'representative'], 'unique' => true],
        'idx_counterparties_company' => ['table' => 'counterparties', 'columns' => ['company'], 'unique' => false],
        'idx_counterparties_role' => ['table' => 'counterparties', 'columns' => ['role'], 'unique' => false],
        'idx_exchange_template_sets_active' => ['table' => 'exchange_template_sets', 'columns' => ['is_active', 'sort_order', 'name'], 'unique' => false],
        'uq_exchange_template_items_code' => ['table' => 'exchange_template_items', 'columns' => ['template_set_id', 'item_code'], 'unique' => true],
        'idx_exchange_template_items_set_order' => ['table' => 'exchange_template_items', 'columns' => ['template_set_id', 'sort_order', 'id'], 'unique' => false],
        'idx_exchange_project_status' => ['table' => 'project_task_exchange', 'columns' => ['project_id', 'status'], 'unique' => false],
        'idx_exchange_deadline' => ['table' => 'project_task_exchange', 'columns' => ['deadline'], 'unique' => false],
        'uq_exchange_project_task' => ['table' => 'project_task_exchange', 'columns' => ['project_id', 'task_id'], 'unique' => true],
        'idx_exchange_task' => ['table' => 'project_task_exchange', 'columns' => ['task_id'], 'unique' => false],
        'idx_exchange_template_item' => ['table' => 'project_task_exchange', 'columns' => ['template_item_id'], 'unique' => false],
        'idx_exchange_direction' => ['table' => 'project_task_exchange', 'columns' => ['direction'], 'unique' => false],
        'idx_exchange_from_user' => ['table' => 'project_task_exchange', 'columns' => ['from_user_id'], 'unique' => false],
        'idx_exchange_to_user' => ['table' => 'project_task_exchange', 'columns' => ['to_user_id'], 'unique' => false],
        'idx_exchange_from_counterparty' => ['table' => 'project_task_exchange', 'columns' => ['from_counterparty_id'], 'unique' => false],
        'idx_exchange_to_counterparty' => ['table' => 'project_task_exchange', 'columns' => ['to_counterparty_id'], 'unique' => false],
        'uq_sbc_reference_hash' => ['table' => 'sbc_items', 'columns' => ['reference_hash'], 'unique' => true],
        'idx_sbc_collection' => ['table' => 'sbc_items', 'columns' => ['collection_code', 'edition'], 'unique' => false],
        'idx_sbc_table_item' => ['table' => 'sbc_items', 'columns' => ['table_code', 'item_code'], 'unique' => false],
        'uq_sbc_indices_period' => ['table' => 'sbc_indices', 'columns' => ['period_key'], 'unique' => true],
        'idx_sbc_indices_active' => ['table' => 'sbc_indices', 'columns' => ['is_active', 'period_key'], 'unique' => false],
        'idx_cost_plan_project_num' => ['table' => 'project_cost_plan', 'columns' => ['project_id', 'num'], 'unique' => false],
        'idx_cost_plan_section' => ['table' => 'project_cost_plan', 'columns' => ['project_id', 'section_code'], 'unique' => false],
        'idx_cost_plan_sbc_item' => ['table' => 'project_cost_plan', 'columns' => ['sbc_item_id'], 'unique' => false],
        'idx_cost_plan_labor_approval' => ['table' => 'project_cost_plan', 'columns' => ['labor_approval_status', 'labor_approved_at'], 'unique' => false],
        'idx_project_labor_project_status' => ['table' => 'project_labor_estimates', 'columns' => ['project_id', 'status'], 'unique' => false],
        'idx_project_labor_section' => ['table' => 'project_labor_estimates', 'columns' => ['section_id'], 'unique' => false],
        'idx_project_labor_task' => ['table' => 'project_labor_estimates', 'columns' => ['task_id'], 'unique' => false],
        'idx_project_labor_executor' => ['table' => 'project_labor_estimates', 'columns' => ['executor_id'], 'unique' => false],
        'idx_project_labor_department' => ['table' => 'project_labor_estimates', 'columns' => ['department_code'], 'unique' => false],
        'idx_project_labor_sbc_item' => ['table' => 'project_labor_estimates', 'columns' => ['sbc_item_id'], 'unique' => false],
        'idx_project_labor_sbc_index' => ['table' => 'project_labor_estimates', 'columns' => ['sbc_index_id'], 'unique' => false],
        'idx_labor_allocations_estimate' => ['table' => 'project_labor_estimate_allocations', 'columns' => ['labor_estimate_id'], 'unique' => false],
        'idx_labor_allocations_user' => ['table' => 'project_labor_estimate_allocations', 'columns' => ['user_id'], 'unique' => false],
        'idx_cost_estimates_status' => ['table' => 'cost_estimates', 'columns' => ['status'], 'unique' => false],
        'idx_cost_estimates_project' => ['table' => 'cost_estimates', 'columns' => ['project_id'], 'unique' => false],
        'idx_cost_estimates_updated' => ['table' => 'cost_estimates', 'columns' => ['updated_at'], 'unique' => false],
        'idx_cost_estimate_items_num' => ['table' => 'cost_estimate_items', 'columns' => ['estimate_id', 'num'], 'unique' => false],
        'idx_cost_estimate_items_section' => ['table' => 'cost_estimate_items', 'columns' => ['estimate_id', 'section_code'], 'unique' => false],
        'idx_cost_estimate_items_sbc' => ['table' => 'cost_estimate_items', 'columns' => ['sbc_item_id'], 'unique' => false],
        'idx_knowledge_folders_parent_order' => ['table' => 'knowledge_folders', 'columns' => ['parent_id', 'sort_order', 'name'], 'unique' => false],
        'uq_knowledge_folders_source_key' => ['table' => 'knowledge_folders', 'columns' => ['source_key'], 'unique' => true],
        'idx_knowledge_documents_folder_status' => ['table' => 'knowledge_documents', 'columns' => ['folder_id', 'status', 'is_pinned', 'sort_order'], 'unique' => false],
        'idx_knowledge_documents_updated' => ['table' => 'knowledge_documents', 'columns' => ['updated_at'], 'unique' => false],
        'uq_knowledge_documents_source_key' => ['table' => 'knowledge_documents', 'columns' => ['source_key'], 'unique' => true],
        'idx_knowledge_revisions_document' => ['table' => 'knowledge_document_revisions', 'columns' => ['document_id', 'version_no'], 'unique' => false],
        'idx_cost_estimate_items_labor_approval' => ['table' => 'cost_estimate_items', 'columns' => ['labor_approval_status', 'labor_approved_at'], 'unique' => false],
        'idx_staffing_period_month_status' => ['table' => 'staffing_periods', 'columns' => ['month_start', 'status', 'revision'], 'unique' => false],
        'idx_staffing_rows_period_department' => ['table' => 'staffing_plan_rows', 'columns' => ['period_id', 'department_code', 'sort_order'], 'unique' => false],
        'uq_custom_field_scope_name' => ['table' => 'custom_fields', 'columns' => ['project_id', 'name'], 'unique' => true],
        'idx_custom_fields_scope_sort' => ['table' => 'custom_fields', 'columns' => ['project_id', 'sort_order'], 'unique' => false],
        'uq_tags_project_slug' => ['table' => 'tags', 'columns' => ['project_id', 'slug'], 'unique' => true],
        'idx_tags_project' => ['table' => 'tags', 'columns' => ['project_id'], 'unique' => false],
        'idx_tags_name' => ['table' => 'tags', 'columns' => ['name'], 'unique' => false],
        'idx_task_tags_tag' => ['table' => 'task_tags', 'columns' => ['tag_id'], 'unique' => false],
        'idx_project_members_project_active' => ['table' => 'project_members', 'columns' => ['project_id', 'active'], 'unique' => false],
        'idx_project_members_user_active' => ['table' => 'project_members', 'columns' => ['user_id', 'active'], 'unique' => false],
        'idx_project_pp_project_active' => ['table' => 'project_pp_codes', 'columns' => ['project_id', 'active', 'sort_order'], 'unique' => false],
        'idx_project_btp_project_active' => ['table' => 'project_btp_codes', 'columns' => ['project_id', 'active', 'sort_order'], 'unique' => false],
        'idx_project_btp_pp' => ['table' => 'project_btp_codes', 'columns' => ['pp_code_id'], 'unique' => false],
        'idx_project_uts_project_date' => ['table' => 'project_uts_facts', 'columns' => ['project_id', 'fact_date'], 'unique' => false],
        'idx_project_uts_pp' => ['table' => 'project_uts_facts', 'columns' => ['pp_code_id'], 'unique' => false],
        'idx_project_uts_btp' => ['table' => 'project_uts_facts', 'columns' => ['btp_code_id'], 'unique' => false],
        'idx_comments_task_created' => ['table' => 'comments', 'columns' => ['task_id', 'created_at'], 'unique' => false],
        'idx_task_logs_task_created' => ['table' => 'task_logs', 'columns' => ['task_id', 'created_at'], 'unique' => false],
        'idx_task_logs_task_field_newval_id' => ['table' => 'task_logs', 'columns' => ['task_id', 'field', 'new_val', 'id'], 'unique' => false],
        'idx_activity_scope_created' => ['table' => 'activity_logs', 'columns' => ['scope', 'created_at'], 'unique' => false],
        'idx_activity_project_created' => ['table' => 'activity_logs', 'columns' => ['project_id', 'created_at'], 'unique' => false],
        'idx_activity_task_created' => ['table' => 'activity_logs', 'columns' => ['task_id', 'created_at'], 'unique' => false],
        'idx_activity_action_created' => ['table' => 'activity_logs', 'columns' => ['action', 'created_at'], 'unique' => false],
        'idx_activity_user_created' => ['table' => 'activity_logs', 'columns' => ['user_id', 'created_at'], 'unique' => false],
        'idx_attachments_task' => ['table' => 'attachments', 'columns' => ['task_id'], 'unique' => false],
        'idx_deadline_shifts_task' => ['table' => 'task_deadline_shifts', 'columns' => ['task_id'], 'unique' => false],
        'idx_deadline_shifts_reason' => ['table' => 'task_deadline_shifts', 'columns' => ['reason_code'], 'unique' => false],
        'idx_deadline_shifts_status' => ['table' => 'task_deadline_shifts', 'columns' => ['task_id', 'status'], 'unique' => false],
        'idx_time_batches_user_period' => ['table' => 'time_batches', 'columns' => ['user_id', 'period_start', 'period_end'], 'unique' => false],
        'idx_time_batches_status' => ['table' => 'time_batches', 'columns' => ['status', 'created_at'], 'unique' => false],
        'idx_time_entries_user_date' => ['table' => 'time_entries', 'columns' => ['user_id', 'work_date'], 'unique' => false],
        'idx_time_entries_task_date' => ['table' => 'time_entries', 'columns' => ['task_id', 'work_date'], 'unique' => false],
        'idx_time_entries_project_date' => ['table' => 'time_entries', 'columns' => ['project_id', 'work_date'], 'unique' => false],
        'idx_time_entries_batch' => ['table' => 'time_entries', 'columns' => ['batch_id'], 'unique' => false],
        'idx_motivation_runs_period' => ['table' => 'motivation_runs', 'columns' => ['period_start', 'period_end'], 'unique' => false],
        'uq_motivation_run_period_state' => ['table' => 'motivation_runs', 'columns' => ['period_start', 'state'], 'unique' => true],
        'idx_motivation_rows_user' => ['table' => 'motivation_run_rows', 'columns' => ['user_id'], 'unique' => false],
        'uq_motivation_run_user' => ['table' => 'motivation_run_rows', 'columns' => ['run_id', 'user_id'], 'unique' => true],
        'idx_pr_questions_template' => ['table' => 'performance_review_questions', 'columns' => ['template_id', 'sort_order'], 'unique' => false],
        'uq_pr_question_key' => ['table' => 'performance_review_questions', 'columns' => ['template_id', 'question_key'], 'unique' => true],
        'idx_pr_cycles_status' => ['table' => 'performance_review_cycles', 'columns' => ['status', 'period_start'], 'unique' => false],
        'idx_pr_cycles_year_kind' => ['table' => 'performance_review_cycles', 'columns' => ['review_year', 'cycle_kind'], 'unique' => false],
        'idx_pr_cycles_audience' => ['table' => 'performance_review_cycles', 'columns' => ['status', 'audience_opened_at'], 'unique' => false],
        'uq_pr_review_user_cycle' => ['table' => 'performance_reviews', 'columns' => ['cycle_id', 'user_id'], 'unique' => true],
        'idx_pr_reviews_user_status' => ['table' => 'performance_reviews', 'columns' => ['user_id', 'status'], 'unique' => false],
        'idx_pr_reviews_manager_status' => ['table' => 'performance_reviews', 'columns' => ['manager_id', 'status'], 'unique' => false],
        'uq_pr_answer_scope' => ['table' => 'performance_review_answers', 'columns' => ['review_id', 'question_id', 'answer_scope'], 'unique' => true],
        'idx_pr_answers_review' => ['table' => 'performance_review_answers', 'columns' => ['review_id', 'answer_scope'], 'unique' => false],
        'uq_pr_competency_scope' => ['table' => 'performance_review_competency_scores', 'columns' => ['review_id', 'competency_key', 'answer_scope'], 'unique' => true],
        'idx_pr_competency_review' => ['table' => 'performance_review_competency_scores', 'columns' => ['review_id', 'answer_scope'], 'unique' => false],
        'uq_pr_cycle_notice' => ['table' => 'performance_review_cycle_notices', 'columns' => ['cycle_id', 'user_id'], 'unique' => true],
        'idx_notifications_user_read' => ['table' => 'notifications', 'columns' => ['user_id', 'read_at'], 'unique' => false],
        'idx_deadline_reminders_user_date' => ['table' => 'deadline_reminders', 'columns' => ['user_id', 'reminder_date'], 'unique' => false],
        'idx_deadline_reminders_task_date' => ['table' => 'deadline_reminders', 'columns' => ['task_id', 'reminder_date'], 'unique' => false],
        'uq_dictionary_scope_kind_value' => ['table' => 'dictionary_items', 'columns' => ['scope_project_id', 'kind', 'value'], 'unique' => true],
        'idx_dictionary_kind_active' => ['table' => 'dictionary_items', 'columns' => ['kind', 'active'], 'unique' => false],
        'idx_dictionary_project_kind' => ['table' => 'dictionary_items', 'columns' => ['scope_project_id', 'kind'], 'unique' => false],
        'idx_project_contacts_project' => ['table' => 'project_contacts', 'columns' => ['project_id'], 'unique' => false],
        'idx_project_contacts_org' => ['table' => 'project_contacts', 'columns' => ['organization'], 'unique' => false],
    ];
}

function ensure_sqlite_index_parity(PDO $pdo): void
{
    foreach (sqlite_required_indexes() as $name => $definition) {
        $columns = implode(', ', array_map('sqlite_quote_identifier', $definition['columns']));
        $unique = $definition['unique'] ? 'UNIQUE ' : '';
        $pdo->exec(sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
            $unique,
            sqlite_quote_identifier($name),
            sqlite_quote_identifier($definition['table']),
            $columns
        ));
    }

    $errors = [];
    foreach (sqlite_required_indexes() as $name => $definition) {
        $indexes = $pdo->query('PRAGMA index_list(' . sqlite_quote_identifier($definition['table']) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $index = null;
        foreach ($indexes as $candidate) {
            if (($candidate['name'] ?? '') === $name) {
                $index = $candidate;
                break;
            }
        }

        if ($index === null) {
            $errors[] = "{$definition['table']}.{$name} is missing";
            continue;
        }

        if ((bool) $index['unique'] !== $definition['unique']) {
            $errors[] = "{$definition['table']}.{$name} unique flag mismatch";
        }

        $info = $pdo->query('PRAGMA index_info(' . sqlite_quote_identifier($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $actualColumns = array_map(static fn (array $row): string => (string) $row['name'], $info);
        if ($actualColumns !== $definition['columns']) {
            $errors[] = "{$definition['table']}.{$name} columns mismatch: " . implode(', ', $actualColumns);
        }
    }

    if ($errors !== []) {
        throw new RuntimeException("SQLite index parity check failed:\n- " . implode("\n- ", $errors));
    }
}

function seed_role_access_permissions(PDO $pdo): void
{
    $stmt = $pdo->prepare('
        INSERT OR IGNORE INTO role_access_permissions (role, capability, enabled, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ');

    foreach (RoleService::roles() as $role) {
        $enabled = array_flip(RoleService::defaultCapabilities($role));
        foreach (RoleService::capabilityKeys() as $capability) {
            $stmt->execute([$role, $capability, isset($enabled[$capability]) ? 1 : 0]);
        }
    }
}

if (config('db.connection') !== 'sqlite') {
    fwrite(STDERR, "DB_CONNECTION must be sqlite for this script.\n");
    exit(1);
}

$path = config('db.sqlite_path');
$dir = dirname($path);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$pdo = Database::pdo();
$schema = file_get_contents(BASE_PATH . '/database/sqlite_schema.sql');
$statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $statement) === 1) {
            continue;
        }

        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'no such column: approval_stage')) {
                throw $e;
            }
        }
    }
}

foreach (['knowledge_folders', 'knowledge_documents'] as $table) {
    $columns = array_column($pdo->query('PRAGMA table_info(' . sqlite_quote_identifier($table) . ')')->fetchAll(), 'name');
    if (!in_array('source_key', $columns, true)) {
        $pdo->exec('ALTER TABLE ' . sqlite_quote_identifier($table) . ' ADD COLUMN source_key TEXT');
    }
}

$atlasRefColumns = array_column($pdo->query('PRAGMA table_info(task_atlas_refs)')->fetchAll(), 'name');
foreach ([
    'viewpoint_json' => 'TEXT',
    'overlay_json' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $atlasRefColumns, true)) {
        $pdo->exec("ALTER TABLE task_atlas_refs ADD COLUMN {$column} {$type}");
    }
}

$taskApprovalsSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'task_approvals'")->fetchColumn();
if ($taskApprovalsSql !== '' && (!str_contains($taskApprovalsSql, 'close_author') || !str_contains($taskApprovalsSql, 'review_task'))) {
    $pdo->exec('ALTER TABLE task_approvals RENAME TO task_approvals_old');
    $pdo->exec("
        CREATE TABLE task_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            stage TEXT NOT NULL CHECK (stage IN ('review_lead', 'review_gip', 'review_task', 'issued', 'close_author', 'close_gip')),
            approved_by INTEGER NOT NULL,
            decision TEXT NOT NULL CHECK (decision IN ('approved', 'rejected', 'issued')),
            comment TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
        )
    ");
    $pdo->exec('
        INSERT INTO task_approvals (id, task_id, stage, approved_by, decision, comment, created_at)
        SELECT id, task_id, stage, approved_by, decision, comment, created_at
        FROM task_approvals_old
    ');
    $pdo->exec('DROP TABLE task_approvals_old');
}

$deadlineShiftColumns = array_column($pdo->query('PRAGMA table_info(task_deadline_shifts)')->fetchAll(), 'name');
foreach ([
    'status' => "TEXT NOT NULL DEFAULT 'approved' CHECK (status IN ('pending','approved','rejected'))",
    'reviewed_by' => 'INTEGER',
    'reviewed_at' => 'TEXT',
    'review_comment' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $deadlineShiftColumns, true)) {
        $pdo->exec("ALTER TABLE task_deadline_shifts ADD COLUMN {$column} {$type}");
    }
}
$pdo->exec('DROP INDEX IF EXISTS idx_deadline_shifts_status');

$userColumns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
foreach ([
    'group_id' => 'INTEGER',
    'position_id' => 'INTEGER',
    'manager_id' => 'INTEGER',
    'password_reset_at' => 'TEXT',
    'password_reset_by' => 'INTEGER',
    'credentials_mail_marked_sent_at' => 'TEXT',
    'credentials_mail_marked_sent_by' => 'INTEGER',
] as $column => $type) {
    if (!in_array($column, $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$type}");
    }
}

$positionColumns = array_column($pdo->query('PRAGMA table_info(positions)')->fetchAll(), 'name');
foreach ([
    'role_key' => 'TEXT',
    'base_role' => "TEXT NOT NULL DEFAULT 'engineer'",
    'description' => 'TEXT',
    'competency_position_index' => 'INTEGER',
    'is_system' => 'INTEGER NOT NULL DEFAULT 0',
    'is_protected' => 'INTEGER NOT NULL DEFAULT 0',
    'is_active' => 'INTEGER NOT NULL DEFAULT 1',
] as $column => $type) {
    if (!in_array($column, $positionColumns, true)) {
        $pdo->exec("ALTER TABLE positions ADD COLUMN {$column} {$type}");
    }
}

$projectColumns = array_column($pdo->query('PRAGMA table_info(projects)')->fetchAll(), 'name');
if (!in_array('file_folder_url', $projectColumns, true)) {
    $pdo->exec("ALTER TABLE projects ADD COLUMN file_folder_url TEXT NOT NULL DEFAULT ''");
}
foreach ([
    'gip_user_id' => 'INTEGER',
    'rp_user_id' => 'INTEGER',
    'archived_at' => 'TEXT',
    'kind' => "TEXT NOT NULL DEFAULT 'project'",
    'address' => 'TEXT',
    'object_type' => 'TEXT',
    'area_m2' => 'REAL',
    'stages_text' => 'TEXT',
    'pp' => 'TEXT',
    'start_date' => 'TEXT',
    'finish_date' => 'TEXT',
    'color' => 'TEXT',
    'speckle_stream_url' => 'TEXT',
    'model_folder_url' => "TEXT NOT NULL DEFAULT ''",
    'budget_manual_thousand' => 'REAL',
    'budget_cost_thousand' => 'REAL NOT NULL DEFAULT 0',
    'budget_profit_thousand' => 'REAL NOT NULL DEFAULT 0',
    'budget_bonus_thousand' => 'REAL NOT NULL DEFAULT 0',
    'budget_comment' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $projectColumns, true)) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN {$column} {$type}");
    }
}

$scheduleColumns = array_column($pdo->query('PRAGMA table_info(project_schedule)')->fetchAll(), 'name');
foreach ([
    'task_id' => 'INTEGER',
    'volume' => 'TEXT',
    'date_issued' => 'TEXT',
    'issue_status' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $scheduleColumns, true)) {
        $pdo->exec("ALTER TABLE project_schedule ADD COLUMN {$column} {$type}");
    }
}

$taskColumns = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
if (!in_array('task_type', $taskColumns, true)) {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN task_type TEXT NOT NULL DEFAULT 'work'");
}
if (!in_array('approval_stage', $taskColumns, true)) {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN approval_stage TEXT NOT NULL DEFAULT 'draft'");
}
foreach ([
    'msp_task_id' => 'INTEGER',
    'msp_outline_level' => 'INTEGER',
    'project_section_id' => 'INTEGER',
    'pp_code_id' => 'INTEGER',
    'btp_code_id' => 'INTEGER',
] as $column => $type) {
    if (!in_array($column, $taskColumns, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN {$column} {$type}");
    }
}
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_approval_stage ON tasks(approval_stage)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_pp_code ON tasks(pp_code_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_btp_code ON tasks(btp_code_id)');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS task_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('assignee','coauthor','observer')),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (task_id, user_id, role),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

$sectionColumns = array_column($pdo->query('PRAGMA table_info(project_sections)')->fetchAll(), 'name');
if (!in_array('task_id', $sectionColumns, true)) {
    $pdo->exec('ALTER TABLE project_sections ADD COLUMN task_id INTEGER');
}
foreach ([
    'reviewer_id' => 'INTEGER',
    'sbc_item_id' => 'INTEGER',
    'sbc_quantity' => 'REAL NOT NULL DEFAULT 1',
    'sbc_stage_percent' => 'REAL NOT NULL DEFAULT 100',
    'sbc_deflator_coeff' => 'REAL NOT NULL DEFAULT 1',
    'sbc_adjustment_coeff' => 'REAL NOT NULL DEFAULT 1',
    'sbc_comment' => 'TEXT',
    'stage_id' => 'INTEGER',
    'work_kind' => 'TEXT NOT NULL DEFAULT "section"',
    'sort_order' => 'INTEGER NOT NULL DEFAULT 0',
    'active' => 'INTEGER NOT NULL DEFAULT 1',
] as $column => $type) {
    if (!in_array($column, $sectionColumns, true)) {
        $pdo->exec("ALTER TABLE project_sections ADD COLUMN {$column} {$type}");
    }
}
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_sections_stage ON project_sections(project_id, stage_id, work_kind, sort_order)');
$pdo->exec("INSERT OR IGNORE INTO project_section_assignments (project_section_id, user_id, assignment_role, sort_order)
    SELECT id, assignee_id, 'executor', 10 FROM project_sections WHERE assignee_id IS NOT NULL");
$pdo->exec("INSERT OR IGNORE INTO project_section_assignments (project_section_id, user_id, assignment_role, sort_order)
    SELECT id, reviewer_id, 'reviewer', 10 FROM project_sections WHERE reviewer_id IS NOT NULL");

$issueColumns = array_column($pdo->query('PRAGMA table_info(project_issues)')->fetchAll(), 'name');
if (!in_array('blocking_task_id', $issueColumns, true)) {
    $pdo->exec('ALTER TABLE project_issues ADD COLUMN blocking_task_id INTEGER');
}

$dataRegistryColumns = array_column($pdo->query('PRAGMA table_info(project_data_registry)')->fetchAll(), 'name');
if (!in_array('blocking_task_ids', $dataRegistryColumns, true)) {
    $pdo->exec('ALTER TABLE project_data_registry ADD COLUMN blocking_task_ids TEXT');
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS counterparties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company TEXT NOT NULL,
        role TEXT,
        representative TEXT,
        contact TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (company, role, representative)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS exchange_template_sets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        scope_section TEXT,
        description TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS exchange_template_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_set_id INTEGER NOT NULL,
        item_code TEXT NOT NULL,
        direction TEXT NOT NULL DEFAULT 'incoming',
        from_section TEXT,
        to_section TEXT,
        assignment TEXT NOT NULL,
        default_status TEXT NOT NULL DEFAULT 'pending',
        comments TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (template_set_id, item_code),
        FOREIGN KEY (template_set_id) REFERENCES exchange_template_sets(id) ON DELETE CASCADE
    )
");

$exchangeColumns = array_column($pdo->query('PRAGMA table_info(project_task_exchange)')->fetchAll(), 'name');
foreach ([
    'task_id' => 'INTEGER',
    'template_item_id' => 'INTEGER',
    'direction' => "TEXT NOT NULL DEFAULT 'outgoing'",
    'from_user_id' => 'INTEGER',
    'from_counterparty_id' => 'INTEGER',
    'from_external_name' => 'TEXT',
    'to_user_id' => 'INTEGER',
    'to_counterparty_id' => 'INTEGER',
    'to_external_name' => 'TEXT',
    'file_url' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $exchangeColumns, true)) {
        $pdo->exec("ALTER TABLE project_task_exchange ADD COLUMN {$column} {$type}");
    }
}

$costPlanColumns = array_column($pdo->query('PRAGMA table_info(project_cost_plan)')->fetchAll(), 'name');
foreach ([
    'sbc_item_id' => 'INTEGER',
    'labor_hours' => 'REAL NOT NULL DEFAULT 0',
    'labor_estimate_method' => "TEXT NOT NULL DEFAULT 'manual'",
    'labor_executor_hours' => 'REAL',
    'labor_gip_hours' => 'REAL',
    'labor_adjustment_hours' => 'REAL',
    'labor_directive_hours' => 'REAL',
    'labor_norm_hours' => 'REAL',
    'labor_productivity_rate' => 'REAL',
    'labor_productivity_coeff' => 'REAL NOT NULL DEFAULT 1',
    'labor_basis' => 'TEXT',
    'labor_approval_status' => "TEXT NOT NULL DEFAULT 'pending_director'",
    'labor_submitted_at' => 'TEXT',
    'labor_approved_by' => 'INTEGER',
    'labor_approved_at' => 'TEXT',
    'labor_approval_comment' => 'TEXT',
    'justification' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $costPlanColumns, true)) {
        $pdo->exec("ALTER TABLE project_cost_plan ADD COLUMN {$column} {$type}");
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS sbc_indices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_key TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        index_value REAL NOT NULL DEFAULT 1,
        source_ref TEXT,
        source_date TEXT,
        comment TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    )
");

$projectLaborColumns = array_column($pdo->query('PRAGMA table_info(project_labor_estimates)')->fetchAll(), 'name');
foreach ([
    'work_title' => 'TEXT',
    'work_description' => 'TEXT',
    'department_code' => 'TEXT',
    'model_object_type' => 'TEXT',
    'model_stage' => 'TEXT',
    'model_area_m2' => 'REAL',
    'model_quantity' => 'REAL NOT NULL DEFAULT 1',
    'model_complexity_coeff' => 'REAL NOT NULL DEFAULT 1',
    'model_typicality_coeff' => 'REAL NOT NULL DEFAULT 1',
    'model_bim_coeff' => 'REAL NOT NULL DEFAULT 1',
    'model_urgency_coeff' => 'REAL NOT NULL DEFAULT 1',
    'model_input_quality_coeff' => 'REAL NOT NULL DEFAULT 1',
    'model_suggested_hours' => 'REAL NOT NULL DEFAULT 0',
    'model_basis' => 'TEXT',
    'sbc_item_id' => 'INTEGER',
    'sbc_quantity' => 'REAL NOT NULL DEFAULT 1',
    'sbc_stage_percent' => 'REAL NOT NULL DEFAULT 100',
    'sbc_index_id' => 'INTEGER',
    'sbc_adjustment_coeff' => 'REAL NOT NULL DEFAULT 1',
    'sbc_cost_snapshot' => 'REAL NOT NULL DEFAULT 0',
    'sbc_basis_snapshot' => 'TEXT',
    'executor_days' => 'REAL',
    'department_submitted_by' => 'INTEGER',
    'department_submitted_at' => 'TEXT',
    'gip_days' => 'REAL',
    'gip_adjusted_at' => 'TEXT',
    'director_days' => 'REAL',
    'director_cost_thousand' => 'REAL NOT NULL DEFAULT 0',
    'director_money_snapshot' => 'TEXT',
    'returned_by' => 'INTEGER',
    'returned_at' => 'TEXT',
    'return_comment' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $projectLaborColumns, true)) {
        $pdo->exec("ALTER TABLE project_labor_estimates ADD COLUMN {$column} {$type}");
    }
}

$projectLaborSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'project_labor_estimates'")->fetchColumn();
if ($projectLaborSql !== '' && (!str_contains($projectLaborSql, 'department_submitted') || str_contains($projectLaborSql, 'task_id INTEGER NOT NULL'))) {
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('DROP TABLE IF EXISTS temp.project_labor_estimates_backup');
    $pdo->exec('DROP TABLE IF EXISTS temp.project_labor_allocations_backup');
    $pdo->exec('CREATE TEMP TABLE project_labor_estimates_backup AS SELECT * FROM project_labor_estimates');
    $pdo->exec('CREATE TEMP TABLE project_labor_allocations_backup AS SELECT * FROM project_labor_estimate_allocations');
    $pdo->exec('DROP TABLE IF EXISTS project_labor_estimate_allocations');
    $pdo->exec('DROP TABLE IF EXISTS project_labor_estimates');
    $pdo->exec("
        CREATE TABLE project_labor_estimates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            work_title TEXT,
            work_description TEXT,
            task_id INTEGER,
            executor_id INTEGER NOT NULL,
            department_code TEXT,
            model_object_type TEXT,
            model_stage TEXT,
            model_area_m2 REAL,
            model_quantity REAL NOT NULL DEFAULT 1,
            model_complexity_coeff REAL NOT NULL DEFAULT 1,
            model_typicality_coeff REAL NOT NULL DEFAULT 1,
            model_bim_coeff REAL NOT NULL DEFAULT 1,
            model_urgency_coeff REAL NOT NULL DEFAULT 1,
            model_input_quality_coeff REAL NOT NULL DEFAULT 1,
            model_suggested_hours REAL NOT NULL DEFAULT 0,
            model_basis TEXT,
            sbc_item_id INTEGER,
            sbc_quantity REAL NOT NULL DEFAULT 1,
            sbc_stage_percent REAL NOT NULL DEFAULT 100,
            sbc_index_id INTEGER,
            sbc_adjustment_coeff REAL NOT NULL DEFAULT 1,
            sbc_cost_snapshot REAL NOT NULL DEFAULT 0,
            sbc_basis_snapshot TEXT,
            requested_by INTEGER,
            executor_hours REAL,
            executor_days REAL,
            executor_comment TEXT,
            executor_submitted_at TEXT,
            department_submitted_by INTEGER,
            department_submitted_at TEXT,
            gip_hours REAL,
            gip_days REAL,
            gip_comment TEXT,
            gip_approved_by INTEGER,
            gip_approved_at TEXT,
            gip_adjusted_at TEXT,
            director_hours REAL,
            director_days REAL,
            director_comment TEXT,
            director_approved_by INTEGER,
            director_approved_at TEXT,
            director_cost_thousand REAL NOT NULL DEFAULT 0,
            director_money_snapshot TEXT,
            returned_by INTEGER,
            returned_at TEXT,
            return_comment TEXT,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','department_submitted','gip_adjusted','returned_to_department','director_approved','assigned','submitted','returned_to_responsible','gip_approved','returned_to_gip')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES project_sections(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
            FOREIGN KEY (executor_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL,
            FOREIGN KEY (sbc_index_id) REFERENCES sbc_indices(id) ON DELETE SET NULL,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (department_submitted_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (gip_approved_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (director_approved_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE project_labor_estimate_allocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            labor_estimate_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hours REAL NOT NULL DEFAULT 0,
            days REAL NOT NULL DEFAULT 0,
            comment TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (labor_estimate_id) REFERENCES project_labor_estimates(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        )
    ");
    $pdo->exec('
        INSERT INTO project_labor_estimates (
            id, project_id, section_id, work_title, work_description, task_id, executor_id, department_code,
            model_object_type, model_stage, model_area_m2, model_quantity, model_complexity_coeff, model_typicality_coeff,
            model_bim_coeff, model_urgency_coeff, model_input_quality_coeff, model_suggested_hours, model_basis,
            sbc_item_id, sbc_quantity, sbc_stage_percent, sbc_index_id, sbc_adjustment_coeff, sbc_cost_snapshot,
            sbc_basis_snapshot, requested_by, executor_hours, executor_days, executor_comment, executor_submitted_at,
            department_submitted_by, department_submitted_at, gip_hours, gip_days, gip_comment, gip_approved_by, gip_approved_at,
            gip_adjusted_at, director_hours, director_days, director_comment, director_approved_by, director_approved_at,
            director_cost_thousand, director_money_snapshot,
            returned_by, returned_at, return_comment, status, created_at, updated_at
        )
        SELECT
            id, project_id, section_id, work_title, work_description, task_id, executor_id, department_code,
            model_object_type, model_stage, model_area_m2, model_quantity, model_complexity_coeff, model_typicality_coeff,
            model_bim_coeff, model_urgency_coeff, model_input_quality_coeff, model_suggested_hours, model_basis,
            sbc_item_id, sbc_quantity, sbc_stage_percent, sbc_index_id, sbc_adjustment_coeff, sbc_cost_snapshot,
            sbc_basis_snapshot, requested_by, executor_hours, executor_days, executor_comment, executor_submitted_at,
            department_submitted_by, department_submitted_at, gip_hours, gip_days, gip_comment, gip_approved_by, gip_approved_at,
            gip_adjusted_at, director_hours, director_days, director_comment, director_approved_by, director_approved_at,
            director_cost_thousand, director_money_snapshot,
            returned_by, returned_at, return_comment, status, created_at, updated_at
        FROM project_labor_estimates_backup
    ');
    $pdo->exec('
        INSERT INTO project_labor_estimate_allocations (id, labor_estimate_id, user_id, hours, days, comment, created_at, updated_at)
        SELECT id, labor_estimate_id, user_id, hours, days, comment, created_at, updated_at
        FROM project_labor_allocations_backup
    ');
    $pdo->exec('DROP TABLE IF EXISTS temp.project_labor_estimates_backup');
    $pdo->exec('DROP TABLE IF EXISTS temp.project_labor_allocations_backup');
    $pdo->exec('PRAGMA foreign_keys = ON');
}

$costEstimateColumns = array_column($pdo->query('PRAGMA table_info(cost_estimates)')->fetchAll(), 'name');
foreach ([
    'object_type' => 'TEXT',
    'region_code' => 'TEXT',
    'object_class' => 'TEXT',
    'work_type' => 'TEXT',
    'area_m2' => 'REAL',
    'floors' => 'REAL',
    'start_date' => 'TEXT',
    'finish_date' => 'TEXT',
    'duration_months' => 'REAL',
    'default_stage_percent' => 'REAL',
    'default_deflator_coeff' => 'REAL',
    'sections_text' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $costEstimateColumns, true)) {
        $pdo->exec("ALTER TABLE cost_estimates ADD COLUMN {$column} {$type}");
    }
}

$costEstimateItemColumns = array_column($pdo->query('PRAGMA table_info(cost_estimate_items)')->fetchAll(), 'name');
foreach ([
    'labor_estimate_method' => "TEXT NOT NULL DEFAULT 'manual'",
    'labor_executor_hours' => 'REAL',
    'labor_gip_hours' => 'REAL',
    'labor_adjustment_hours' => 'REAL',
    'labor_directive_hours' => 'REAL',
    'labor_norm_hours' => 'REAL',
    'labor_productivity_rate' => 'REAL',
    'labor_productivity_coeff' => 'REAL NOT NULL DEFAULT 1',
    'labor_basis' => 'TEXT',
    'labor_approval_status' => "TEXT NOT NULL DEFAULT 'pending_director'",
    'labor_submitted_at' => 'TEXT',
    'labor_approved_by' => 'INTEGER',
    'labor_approved_at' => 'TEXT',
    'labor_approval_comment' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $costEstimateItemColumns, true)) {
        $pdo->exec("ALTER TABLE cost_estimate_items ADD COLUMN {$column} {$type}");
    }
}

$timeEntriesSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'time_entries'")->fetchColumn();
if ($timeEntriesSql !== '' && !str_contains($timeEntriesSql, 'sick_leave')) {
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('DROP TABLE IF EXISTS temp.time_entries_backup');
    $pdo->exec('CREATE TEMP TABLE time_entries_backup AS SELECT * FROM time_entries');
    $pdo->exec('DROP TABLE IF EXISTS time_entries');
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER,
            user_id INTEGER NOT NULL,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT NOT NULL,
            minutes INTEGER NOT NULL,
            category TEXT NOT NULL DEFAULT 'task' CHECK (category IN ('task','meeting','admin','learning','vacation','sick_leave','business_trip','day_off','idle','absence','overtime','other')),
            phase TEXT NOT NULL DEFAULT 'execution' CHECK (phase IN ('execution','review','correction','repeat_review','management','other')),
            comment TEXT,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved','locked')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (batch_id) REFERENCES time_batches(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec('
        INSERT INTO time_entries (
            id, batch_id, user_id, project_id, task_id, work_date, minutes, category, phase, comment, status, created_at, updated_at
        )
        SELECT
            id, batch_id, user_id, project_id, task_id, work_date, minutes, category, phase, comment, status, created_at, updated_at
        FROM time_entries_backup
    ');
    $pdo->exec('DROP TABLE IF EXISTS temp.time_entries_backup');
    $pdo->exec('PRAGMA foreign_keys = ON');
}

$timeMonthReviewColumns = array_column($pdo->query('PRAGMA table_info(time_month_reviews)')->fetchAll(), 'name');
foreach ([
    'department_approved_at' => 'TEXT',
    'department_approved_by' => 'INTEGER',
] as $column => $type) {
    if (!in_array($column, $timeMonthReviewColumns, true)) {
        $pdo->exec("ALTER TABLE time_month_reviews ADD COLUMN {$column} {$type}");
    }
}

$pdo->exec('
    UPDATE project_cost_plan
    SET labor_approval_status = COALESCE(NULLIF(labor_approval_status, ""), "pending_director"),
        labor_submitted_at = COALESCE(labor_submitted_at, CURRENT_TIMESTAMP)
    WHERE labor_approval_status IS NULL
       OR labor_approval_status = ""
       OR labor_submitted_at IS NULL
');
$pdo->exec('
    UPDATE cost_estimate_items
    SET labor_approval_status = COALESCE(NULLIF(labor_approval_status, ""), "pending_director"),
        labor_submitted_at = COALESCE(labor_submitted_at, CURRENT_TIMESTAMP)
    WHERE labor_approval_status IS NULL
       OR labor_approval_status = ""
       OR labor_submitted_at IS NULL
');

$pdo->exec("
    UPDATE tasks
    SET task_type = 'assignment'
    WHERE EXISTS (
        SELECT 1
        FROM project_task_exchange
        WHERE project_task_exchange.task_id = tasks.id
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        is_builtin INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_questions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        question_key TEXT NOT NULL,
        label TEXT NOT NULL,
        question_type TEXT NOT NULL DEFAULT 'textarea' CHECK (question_type IN ('text','textarea','rating_1_5','yes_no')),
        answer_scope TEXT NOT NULL DEFAULT 'both' CHECK (answer_scope IN ('self','manager','hr','both')),
        is_required INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(template_id, question_key),
        FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE CASCADE
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_cycles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        period_start TEXT,
        period_end TEXT,
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','closed','cancelled')),
        created_by INTEGER,
        closed_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT,
        FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cycle_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        manager_id INTEGER,
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','self_review','manager_review','hr_review','closed','cancelled')),
        self_submitted_at TEXT,
        manager_submitted_at TEXT,
        hr_closed_at TEXT,
        hr_closed_by INTEGER,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(cycle_id, user_id),
        FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (hr_closed_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_answers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        review_id INTEGER NOT NULL,
        question_id INTEGER NOT NULL,
        answer_scope TEXT NOT NULL CHECK (answer_scope IN ('self','manager','hr')),
        answer_value TEXT,
        question_label_snapshot TEXT NOT NULL,
        question_type_snapshot TEXT NOT NULL,
        answered_by INTEGER,
        answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(review_id, question_id, answer_scope),
        FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES performance_review_questions(id) ON DELETE RESTRICT,
        FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
    )
");
$performanceReviewQuestionColumns = array_column($pdo->query('PRAGMA table_info(performance_review_questions)')->fetchAll(), 'name');
foreach (['section_key' => 'TEXT', 'section_label' => 'TEXT'] as $column => $type) {
    if (!in_array($column, $performanceReviewQuestionColumns, true)) {
        $pdo->exec("ALTER TABLE performance_review_questions ADD COLUMN {$column} {$type}");
    }
}
$performanceReviewCycleColumns = array_column($pdo->query('PRAGMA table_info(performance_review_cycles)')->fetchAll(), 'name');
foreach ([
    'cycle_kind' => "TEXT NOT NULL DEFAULT 'annual'",
    'review_year' => 'INTEGER',
    'response_deadline' => 'TEXT',
    'questionnaire_snapshot_json' => 'TEXT',
    'competency_snapshot_json' => 'TEXT',
    'audience_opened_at' => 'TEXT',
    'audience_opened_by' => 'INTEGER',
] as $column => $type) {
    if (!in_array($column, $performanceReviewCycleColumns, true)) {
        $pdo->exec("ALTER TABLE performance_review_cycles ADD COLUMN {$column} {$type}");
    }
}
$cycleYearUnique = false;
foreach ($pdo->query("PRAGMA index_list('performance_review_cycles')")->fetchAll() as $indexRow) {
    if ((int) ($indexRow['unique'] ?? 0) !== 1) {
        continue;
    }
    $indexName = str_replace("'", "''", (string) ($indexRow['name'] ?? ''));
    $indexColumns = array_column($pdo->query("PRAGMA index_info('{$indexName}')")->fetchAll(), 'name');
    if ($indexColumns === ['review_year']) {
        $cycleYearUnique = true;
        break;
    }
}
if ($cycleYearUnique) {
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('DROP TABLE IF EXISTS performance_review_cycles_rebuild');
    $pdo->exec("
        CREATE TABLE performance_review_cycles_rebuild (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            cycle_kind TEXT NOT NULL DEFAULT 'annual' CHECK (cycle_kind IN ('annual','test')),
            review_year INTEGER,
            period_start TEXT,
            period_end TEXT,
            response_deadline TEXT,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','closed','cancelled')),
            questionnaire_snapshot_json TEXT,
            competency_snapshot_json TEXT,
            audience_opened_at TEXT,
            audience_opened_by INTEGER,
            created_by INTEGER,
            closed_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT,
            FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE RESTRICT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("INSERT INTO performance_review_cycles_rebuild (
        id, template_id, title, cycle_kind, review_year, period_start, period_end, response_deadline, status,
        questionnaire_snapshot_json, competency_snapshot_json, audience_opened_at, audience_opened_by,
        created_by, closed_by, created_at, updated_at, closed_at
    ) SELECT id, template_id, title, COALESCE(cycle_kind, 'annual'), review_year, period_start, period_end,
        response_deadline, status, questionnaire_snapshot_json, competency_snapshot_json, audience_opened_at,
        audience_opened_by, created_by, closed_by, created_at, updated_at, closed_at
      FROM performance_review_cycles");
    $pdo->exec('DROP TABLE performance_review_cycles');
    $pdo->exec('ALTER TABLE performance_review_cycles_rebuild RENAME TO performance_review_cycles');
    $pdo->exec('PRAGMA foreign_keys = ON');
}
$pdo->exec('DROP INDEX IF EXISTS uq_pr_cycle_review_year');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_pr_cycles_year_kind ON performance_review_cycles(review_year, cycle_kind)');
$performanceReviewColumns = array_column($pdo->query('PRAGMA table_info(performance_reviews)')->fetchAll(), 'name');
foreach ([
    'position_title_snapshot' => 'TEXT',
    'position_grade_snapshot' => 'TEXT',
    'competency_position_index' => 'INTEGER',
    'launch_batch_no' => 'INTEGER',
    'launched_at' => 'TEXT',
    'self_questionnaire_submitted_at' => 'TEXT',
    'self_matrix_submitted_at' => 'TEXT',
    'manager_matrix_submitted_at' => 'TEXT',
    'meeting_completed_at' => 'TEXT',
    'meeting_completed_by' => 'INTEGER',
    'meeting_notes' => 'TEXT',
    'next_year_actions' => 'TEXT',
] as $column => $type) {
    if (!in_array($column, $performanceReviewColumns, true)) {
        $pdo->exec("ALTER TABLE performance_reviews ADD COLUMN {$column} {$type}");
    }
}
$notificationColumns = array_column($pdo->query('PRAGMA table_info(notifications)')->fetchAll(), 'name');
if (!in_array('target_url', $notificationColumns, true)) {
    $pdo->exec('ALTER TABLE notifications ADD COLUMN target_url TEXT');
}
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_competency_scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        review_id INTEGER NOT NULL,
        competency_key TEXT NOT NULL,
        answer_scope TEXT NOT NULL CHECK (answer_scope IN ('self','manager')),
        score INTEGER CHECK (score BETWEEN 1 AND 5),
        comment TEXT,
        competency_name_snapshot TEXT NOT NULL,
        competency_description_snapshot TEXT,
        level_1_snapshot TEXT,
        level_2_snapshot TEXT,
        level_3_snapshot TEXT,
        level_4_snapshot TEXT,
        level_5_snapshot TEXT,
        required_level_snapshot INTEGER,
        answered_by INTEGER,
        answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(review_id, competency_key, answer_scope),
        FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS performance_review_cycle_notices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cycle_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        notification_id INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(cycle_id, user_id),
        FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
    )
");
$performanceReviewQuestionColumns = array_column($pdo->query('PRAGMA table_info(performance_review_questions)')->fetchAll(), 'name');
if (!in_array('answer_scope', $performanceReviewQuestionColumns, true)) {
    $pdo->exec("ALTER TABLE performance_review_questions ADD COLUMN answer_scope TEXT NOT NULL DEFAULT 'both' CHECK (answer_scope IN ('self','manager','hr','both'))");
}
(new \App\Services\PerformanceReviewService($pdo))->seedDefaults();

ensure_sqlite_index_parity($pdo);
seed_role_access_permissions($pdo);
\App\Services\OrgService::seedDefaultPositions($pdo);
\App\Services\PositionService::ensureMetadata($pdo);
$performanceMatrix = require BASE_PATH . '/config/performance_review_matrix.php';
$mapPerformancePosition = $pdo->prepare('UPDATE positions SET competency_position_index = ? WHERE title = ? AND competency_position_index IS NULL');
foreach ((array) ($performanceMatrix['positions'] ?? []) as $matrixIndex => $matrixPosition) {
    $mapPerformancePosition->execute([(int) $matrixIndex, (string) ($matrixPosition['title'] ?? '')]);
}
$pdo->exec("UPDATE users SET position_id = (SELECT id FROM positions WHERE positions.role_key = users.role LIMIT 1) WHERE position_id IS NULL AND role <> 'admin'");
$positionAssignments = $pdo->query("SELECT u.id AS user_id, u.role, p.id AS position_id, p.role_key, p.title, p.grade, p.sort_order
    FROM users u JOIN positions p ON p.id = u.position_id WHERE u.role <> 'admin'")->fetchAll();
$findPositionByKey = $pdo->prepare('SELECT id FROM positions WHERE role_key = ? LIMIT 1');
$findPositionByTitle = $pdo->prepare('SELECT id FROM positions WHERE title = ? LIMIT 1');
$insertLegacyPosition = $pdo->prepare('INSERT INTO positions
    (role_key, base_role, title, grade, description, sort_order, is_system, is_protected, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1)');
$syncUserPosition = $pdo->prepare('UPDATE users SET position_id = ?, role = ? WHERE id = ?');
foreach ($positionAssignments as $assignment) {
    $currentRole = (string) $assignment['role'];
    $positionRole = (string) ($assignment['role_key'] ?? '');
    if ($positionRole !== '' && $currentRole === $positionRole) {
        continue;
    }
    $legacyKey = 'legacy_' . (int) $assignment['position_id'] . '_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($currentRole));
    $findPositionByKey->execute([$legacyKey]);
    $targetId = (int) ($findPositionByKey->fetchColumn() ?: 0);
    if ($targetId === 0) {
        $baseTitle = (string) $assignment['title'] . ' — ' . \App\Services\RoleService::label($currentRole);
        $title = $baseTitle;
        $suffix = 2;
        do {
            $findPositionByTitle->execute([$title]);
            $titleExists = $findPositionByTitle->fetchColumn() !== false;
            if ($titleExists) {
                $title = $baseTitle . ' ' . $suffix++;
            }
        } while ($titleExists);
        $baseRole = \App\Services\RoleService::normalize($currentRole);
        $insertLegacyPosition->execute([
            $legacyKey,
            $baseRole,
            $title,
            $assignment['grade'] ?: null,
            'Автоматически создано миграцией для сохранения прежних полномочий.',
            (int) ($assignment['sort_order'] ?? 100) + 1,
        ]);
        $targetId = (int) $pdo->lastInsertId();
    }
    $syncUserPosition->execute([$targetId, $legacyKey, (int) $assignment['user_id']]);
}

$pdo->exec('
    UPDATE tasks
    SET approval_stage = "issued"
    WHERE approval_stage = "draft"
      AND EXISTS (
          SELECT 1
          FROM task_issuances
          WHERE task_issuances.task_id = tasks.id
      )
');

$reasons = [
    ['client_data', 'Задержка исходных данных от заказчика'],
    ['interdep_data', 'Задержка задания от смежного раздела'],
    ['tech_change', 'Изменение технического задания'],
    ['resource', 'Недостаток ресурса исполнителя'],
    ['expert', 'Замечания экспертизы'],
    ['other', 'Иное (требует развёрнутого комментария)'],
];
foreach ($reasons as [$code, $label]) {
    $pdo->prepare('INSERT OR IGNORE INTO deadline_shift_reasons (code, label, active) VALUES (?, ?, 1)')->execute([$code, $label]);
}

// ЦФО fallback hourly rates (medians) — used in reports when a user has no personal employee_rate.
$cfoRates = [
    ['АР', 358, 'Архитектурный отдел (медиана ЦФО)'],
    ['КР', 690, 'Конструкции (медиана ДПР)'],
    ['ОВ', 690, 'ОВиК (медиана ДПР)'],
    ['ВК', 951, 'Водоснабжение и водоотведение (медиана ЦФО)'],
    ['ЭОМ', 609, 'Электрооборудование (медиана ЦФО)'],
    ['СС', 571, 'Системы связи / СКС (медиана ЦФО)'],
    ['АСУ', 781, 'АСУЗ (медиана ЦФО)'],
    ['BIM', 431, 'ТИМ / BIM (медиана ЦФО)'],
    ['ГИП', 848, 'Бюро ГИП (медиана ЦФО)'],
    ['ДПР', 690, 'Департамент проектирования (медиана)'],
];
foreach ($cfoRates as [$code, $rate, $label]) {
    $pdo->prepare('INSERT OR IGNORE INTO cfo_rates (dept_code, hourly_rate, label) VALUES (?, ?, ?)')->execute([$code, $rate, $label]);
}

$password = 'admin12345';
$pdo->prepare('
    INSERT OR IGNORE INTO users (id, tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES (1, "0001", "Администратор", "admin@example.local", ?, "admin", "ДПР", 0)
')->execute([password_hash($password, PASSWORD_DEFAULT)]);

$pdo->prepare('
    INSERT OR IGNORE INTO users (id, tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES (2, "0101", "Иван Петров", "designer@example.local", ?, "engineer", "ОВ", 0)
')->execute([password_hash('designer123', PASSWORD_DEFAULT)]);

$pdo->prepare('
    INSERT OR IGNORE INTO users (tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES ("0002", "Директор", "director@example.local", ?, "director", "Дирекция", 0)
')->execute([password_hash('director123', PASSWORD_DEFAULT)]);

$pdo->prepare('
    INSERT OR IGNORE INTO users (tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES ("0003", "Зам. директора", "deputy@example.local", ?, "deputy_director", "Дирекция", 0)
')->execute([password_hash('deputy123', PASSWORD_DEFAULT)]);

$pdo->prepare('
    INSERT OR IGNORE INTO users (tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES ("0004", "Руководитель проекта", "project.manager@example.local", ?, "project_manager", "Внешние участники", 0)
')->execute([password_hash('project123', PASSWORD_DEFAULT)]);

$pdo->prepare('
    INSERT OR IGNORE INTO users (tab_number, name, email, password_hash, role, department, must_change_password)
    VALUES ("0005", "Директор смежников", "adjacent.director@example.local", ?, "adjacent_director", "Внешние участники", 0)
')->execute([password_hash('adjacent123', PASSWORD_DEFAULT)]);

$pdo->exec("
    UPDATE users
    SET role = CASE role
        WHEN 'designer' THEN 'engineer'
        WHEN 'lead' THEN 'group_lead'
        WHEN 'head' THEN 'department_head'
        ELSE role
    END
    WHERE role IN ('designer', 'lead', 'head')
");

$departmentUsers = require __DIR__ . '/department_users.php';
$departmentPasswordHash = password_hash('dpr12345', PASSWORD_DEFAULT);
$findDepartmentUser = $pdo->prepare('
    SELECT id
    FROM users
    WHERE tab_number = ? OR email = ?
    ORDER BY CASE WHEN tab_number = ? THEN 0 ELSE 1 END
    LIMIT 1
');
$updateDepartmentUser = $pdo->prepare('
    UPDATE users
    SET tab_number = ?, name = ?, email = ?, role = ?, department = ?, is_active = 1
    WHERE id = ?
');
$insertDepartmentUser = $pdo->prepare('
    INSERT INTO users (tab_number, name, email, password_hash, role, department, must_change_password, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1, 1)
');
foreach ($departmentUsers as $departmentUser) {
    $findDepartmentUser->execute([$departmentUser['tab_number'], $departmentUser['email'], $departmentUser['tab_number']]);
    $departmentUserId = $findDepartmentUser->fetchColumn();
    if ($departmentUserId) {
        $updateDepartmentUser->execute([$departmentUser['tab_number'], $departmentUser['name'], $departmentUser['email'], $departmentUser['role'], $departmentUser['department'], $departmentUserId]);
        continue;
    }

    $insertDepartmentUser->execute([$departmentUser['tab_number'], $departmentUser['name'], $departmentUser['email'], $departmentPasswordHash, $departmentUser['role'], $departmentUser['department']]);
}

$pdo->exec("
    INSERT OR IGNORE INTO departments (code, name) VALUES
    ('ОВ', 'Отдел отопления и вентиляции'),
    ('ЭОМ', 'Отдел силового электрооборудования и электроосвещения'),
    ('СС', 'Отдел систем связи'),
    ('ГИП', 'Служба главных инженеров проектов (ГИП)'),
    ('BIM', 'Отдел ТИМ/BIM моделирования'),
    ('АСУ', 'Отдел автоматизированных систем управления'),
    ('ВК', 'Отдел водоснабжения и водоотведения'),
    ('КР', 'Отдел конструктивных решений'),
    ('АР', 'Отдел архитектурных решений')
");

$pdo->exec("
    INSERT OR IGNORE INTO departments (code, name) VALUES
    ('ДП', 'Дирекция департамента'),
    ('ТИМ', 'Отдел технологий информационного моделирования'),
    ('НК', 'Отдел нормоконтроля'),
    ('КС-СКС', 'Отдел кабельных систем и структурированных сетей'),
    ('СПЗ', 'Отдел специальных проектов защиты'),
    ('КСБ', 'Отдел комплексных систем безопасности');
    UPDATE users SET department = 'ТИМ' WHERE department = 'BIM';
    UPDATE users SET department = 'КС-СКС' WHERE department = 'СС';
    UPDATE users SET department = 'ДП' WHERE department = 'ДПР';
    UPDATE department_groups SET department_code = 'ТИМ' WHERE department_code = 'BIM';
    UPDATE department_groups SET department_code = 'КС-СКС' WHERE department_code = 'СС';
    DELETE FROM departments WHERE code IN ('BIM', 'СС');
");

$pdo->exec("
    UPDATE departments
    SET head_user_id = (
        SELECT id FROM users
        WHERE users.department = departments.code
          AND (users.role = 'department_head' OR users.role = 'gip')
        LIMIT 1
    )
");

$pdo->exec('
    INSERT OR IGNORE INTO projects (id, code, title, object, stage, status, color, speckle_stream_url, file_folder_url)
    VALUES (1, "D-BASE", "Базовый демо-проект", "Корпус 1", "РД", "active", "#cc1f1f", "https://example.invalid/model", "")
');

$pdo->exec('
    UPDATE projects
    SET file_folder_url = ""
    WHERE file_folder_url = ""
');

$pdo->exec('
    UPDATE projects
    SET gip_user_id = COALESCE((SELECT id FROM users WHERE email = "head.gip@example.local" LIMIT 1), gip_user_id, 1)
    WHERE gip_user_id IS NULL OR gip_user_id = 1
');

$pdo->exec('
    UPDATE projects
    SET rp_user_id = COALESCE((SELECT id FROM users WHERE email = "head.ov@example.local" LIMIT 1), rp_user_id, 2)
    WHERE rp_user_id IS NULL OR rp_user_id = 2
');

$pdo->exec('
    INSERT INTO project_contacts (project_id, full_name, contact, organization, position)
    SELECT 1, "Анна Смирнова", "anna.smirnova@example.local, +7 999 100-20-30", "Заказчик", "Представитель заказчика"
    WHERE NOT EXISTS (
        SELECT 1 FROM project_contacts
        WHERE project_id = 1 AND full_name = "Анна Смирнова" AND organization = "Заказчик"
    )
');

$pdo->exec('
    INSERT OR IGNORE INTO tasks (id, title, project_id, assignee_id, author_id, reviewer_id, discipline, volume, section, status, priority, urgency, date_start, date_end, date_end_original, planned_hours, progress, btp)
    VALUES
    (1, "Подготовить комплект ОВ для выдачи", 1, 2, 1, 1, "ОВ", "15.6.3.1", "ОВ", "in_progress", "high", "high", date("now"), date("now", "+5 day"), date("now", "+5 day"), 40, 45, "Проверить увязку с АР и ВК."),
    (2, "Собрать замечания по исходным данным", 1, 2, 1, 1, "ПЗ", "1", "ПЗ", "overdue", "mid", "high", date("now", "-10 day"), date("now", "-1 day"), date("now", "-1 day"), 24, 70, "Нужны письма заказчика.")
');

$pdo->exec('
    UPDATE tasks
    SET author_id = COALESCE((SELECT id FROM users WHERE email = "chief.ov@example.local" LIMIT 1), author_id),
        reviewer_id = COALESCE((SELECT id FROM users WHERE email = "group.ov@example.local" LIMIT 1), reviewer_id)
    WHERE id IN (1, 2)
');

$pdo->exec('
    INSERT OR IGNORE INTO task_smart (task_id, what, when_due, why, depends_on)
    VALUES
    (1, "Выпустить рабочий комплект ОВ с ведомостями", date("now", "+5 day"), "Закрыть контрольную точку проекта", "АР-основы, ВК-задание"),
    (2, "Собрать перечень отсутствующих исходных данных", date("now", "-1 day"), "Снять блокеры по проекту", "Ответ заказчика")
');

$pdo->exec('
    INSERT OR IGNORE INTO task_issuances (id, task_id, issue_number, issued_at, issued_by, comment, status)
    VALUES
    (1, 1, 1, "2026-03-14", 2, "Первичная выдача комплекта ОВ", "remarks"),
    (2, 1, 2, "2026-03-28", 2, "Повторная выдача после корректировок", "remarks"),
    (3, 1, 3, "2026-04-10", 1, "", "accepted")
');
$pdo->exec('
    UPDATE tasks
    SET approval_stage = "issued"
    WHERE approval_stage = "draft"
      AND EXISTS (
          SELECT 1
          FROM task_issuances
          WHERE task_issuances.task_id = tasks.id
      )
');

$pdo->exec('
    INSERT INTO project_schedule (project_id, task_id, volume, object, section, object_type, has_id, id_readiness, rd_readiness, rd_readiness_label, rd_date_plan, date_issued, issue_status, assignee_id, comments)
    SELECT 1, 1, "15.6.3.1", "Корпус 1", "ОВ", "Средняя", 1, 80, 55, "В работе", date("now", "+7 day"), NULL, "В работе", 2, "Проверить коллизии"
    WHERE NOT EXISTS (
        SELECT 1 FROM project_schedule WHERE project_id = 1 AND task_id = 1
    )
');

$pdo->exec('
    INSERT INTO project_issues (project_id, blocking_task_id, num, section_code, issue, assignee_id, stage, date_raised, notes, status)
    SELECT 1, 2, 1, "ОВ", "Нет подтверждения тепловых нагрузок", 2, "У заказчика", date("now", "-2 day"), "Блокирует расчёт", "open"
    WHERE NOT EXISTS (
        SELECT 1 FROM project_issues
        WHERE project_id = 1 AND num = 1 AND section_code = "ОВ" AND issue = "Нет подтверждения тепловых нагрузок"
    )
');
$pdo->exec('
    UPDATE project_issues
    SET blocking_task_id = 2
    WHERE project_id = 1 AND num = 1 AND blocking_task_id IS NULL
');

$pdo->exec('
    INSERT INTO project_data_registry (project_id, blocking_task_ids, num, section_code, missing_data, responsible, status, date_requested, date_received_plan, impact, comments)
    SELECT 1, "2", 1, "ОВ", "Технические условия на тепло", "Заказчик", "waiting", date("now", "-5 day"), date("now", "+2 day"), "Расчёт нагрузок", ""
    WHERE NOT EXISTS (
        SELECT 1 FROM project_data_registry
        WHERE project_id = 1 AND num = 1 AND section_code = "ОВ" AND missing_data = "Технические условия на тепло"
    )
');
$pdo->exec('
    UPDATE project_data_registry
    SET blocking_task_ids = "2"
    WHERE project_id = 1 AND num = 1 AND COALESCE(blocking_task_ids, "") = ""
');

$pdo->exec('
    INSERT OR IGNORE INTO project_sections (project_id, task_id, volume, code, title, status, date_start, date_end, assignee_id, comments)
    SELECT project_id, id, volume, section, title, status, date_start, date_end, assignee_id, "Из задачи #" || id
    FROM tasks
    WHERE id = 1
      AND NOT EXISTS (SELECT 1 FROM project_sections WHERE task_id = tasks.id)
');

$pdo->exec('
    INSERT INTO project_cost_plan (
        project_id, num, section_code, sbc_collection, sbc_table, work_name, unit,
        labor_hours, quantity, base_price, stage_percent, complexity_coeff, deflator_coeff, adjustment_coeff,
        planned_cost, price_level, justification, comments
    )
    SELECT 1, 1, "ОВ", "СБЦ: заполнить", "", "Разработка комплекта ОВ", "раздел",
           0, 1, 0, 100, 1, 1, 1, 0, "база СБЦ",
           "Демо-обоснование: позиция ОВ из перечня разделов, нормативный пункт и трудозатраты уточняются.",
           "Демо-строка: укажите сборник, таблицу/пункт, базовую цену и трудозатраты."
    WHERE NOT EXISTS (
        SELECT 1 FROM project_cost_plan WHERE project_id = 1 AND num = 1
    )
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS project_model_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        model_url TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT "json",
        model_scope TEXT NOT NULL DEFAULT "project",
        discipline TEXT,
        revision TEXT,
        notes TEXT,
        is_primary INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )
');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_model_links_project ON project_model_links(project_id, is_primary, created_at)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_model_links_kind ON project_model_links(kind)');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS project_payment_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        payment_name TEXT NOT NULL,
        planned_date TEXT NOT NULL,
        planned_amount REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "planned",
        invoice_date TEXT,
        actual_date TEXT,
        actual_amount REAL NOT NULL DEFAULT 0,
        comment TEXT,
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_by INTEGER,
        updated_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    )
');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_payment_schedule_project_date ON project_payment_schedule(project_id, planned_date, status)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_payment_schedule_actual_date ON project_payment_schedule(actual_date, status)');

$dictionaryItems = [
    [0, null, 'volume', '1', 'Том 1', null, 10],
    [0, null, 'volume', '15.6.3.1', '15.6.3.1', null, 20],
    [0, null, 'section_code', 'ОВ', 'ОВ — Отопление и вентиляция', 'ОВ', 10],
    [0, null, 'section_code', 'ВК', 'ВК — Водоснабжение и канализация', 'ВК', 20],
    [0, null, 'section_code', 'АР', 'АР — Архитектурные решения', 'АР', 30],
    [0, null, 'section_code', 'КР', 'КР — Конструктивные решения', 'КР', 40],
    [0, null, 'section_code', 'ЭОМ', 'ЭОМ — Электрооборудование', 'ЭОМ', 50],
    [0, null, 'section', 'ОВ', 'ОВ', 'ОВ', 10],
    [0, null, 'section', 'ВК', 'ВК', 'ВК', 20],
    [0, null, 'section', 'АР', 'АР', 'АР', 30],
    [1, 1, 'volume', '15.6.3.1', 'Базовый демо-проект · том 15.6.3.1', null, 10],
    [1, 1, 'section_code', 'DEMO/2026-ОВ', 'DEMO/2026-ОВ', 'ОВ', 10],
    [1, 1, 'section_code', 'DEMO/2026-ВК', 'DEMO/2026-ВК', 'ВК', 20],
];
$dictStmt = $pdo->prepare('
    INSERT OR IGNORE INTO dictionary_items (scope_project_id, project_id, kind, value, label, discipline, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
foreach ($dictionaryItems as $item) {
    $dictStmt->execute($item);
}

$customFields = [
    ['file_links', 'Файлы задачи', 'links', null, null, 0, 90],
    ['bim_model', 'Модель', 'text', null, null, 0, 31],
    ['bim_image', 'Изображение', 'link', null, null, 0, 32],
    ['bim_response', 'Ответ BIM отдела', 'text', null, null, 0, 33],
    ['bim_electrical_connectors', 'Электрические коннекторы', 'select', null, '["Не требуется","Требуется","Настроить","Проверить"]', 0, 34],
];
$customFieldStmt = $pdo->prepare('
    INSERT OR IGNORE INTO custom_fields (name, label, type, project_id, options, required, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$customFieldExists = $pdo->prepare('
    SELECT COUNT(*)
    FROM custom_fields
    WHERE name = ?
      AND (
          (project_id IS NULL AND ? IS NULL)
          OR project_id = ?
      )
');
foreach ($customFields as $customField) {
    $projectId = $customField[3];
    $customFieldExists->execute([$customField[0], $projectId, $projectId]);
    if ((int) $customFieldExists->fetchColumn() === 0) {
        $customFieldStmt->execute($customField);
    }
}

$exchangeTemplateSets = [
    ['asu_base', 'АСУ: типовой обмен заданиями', 'АСУ', 'Входящие и исходящие задания группы АСУ по положению об обмене заданиями.', 10],
    ['cable_trays', 'Кабельные лотки: трассы кабелей', 'Лотки', 'Все кабельные разделы выдают трассы кабелей; по ним формируется схема и планы лотков.', 20],
];
$templateSetStmt = $pdo->prepare('
    INSERT OR IGNORE INTO exchange_template_sets (code, name, scope_section, description, sort_order)
    VALUES (?, ?, ?, ?, ?)
');
foreach ($exchangeTemplateSets as $set) {
    $templateSetStmt->execute($set);
}

$templateIds = [];
foreach ($pdo->query('SELECT id, code FROM exchange_template_sets')->fetchAll() as $set) {
    $templateIds[(string) $set['code']] = (int) $set['id'];
}

$exchangeTemplateItems = [
    ['asu_base', 'in_ovik', 'incoming', 'ОВиК', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, описание работы агрегатов при переходе погодных режимов, технические подборы оборудования, ХОВС, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ОВиК.', 10],
    ['asu_base', 'in_vk', 'incoming', 'ВК', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ВК.', 20],
    ['asu_base', 'in_eom', 'incoming', 'ЭОМ', 'АСУ', 'Однолинейные схемы электроснабжения, планы расположения оборудования, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ЭОМ.', 30],
    ['asu_base', 'in_tm', 'incoming', 'ТМ', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ТМ.', 40],
    ['asu_base', 'in_tx', 'incoming', 'ТХ', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ТХ.', 50],
    ['asu_base', 'in_ar', 'incoming', 'АР (АИ)', 'АСУ', 'Планы расположения оконечных устройств и групп управления освещения, технические подборы оборудования, сценарии управления', 'pending', 'Матрица АСУ: входящее задание от АР (АИ).', 60],
    ['asu_base', 'out_eom', 'outgoing', 'АСУ', 'ЭОМ', 'Перечень оборудования автоматизации для обеспечения электроснабжением: категория электроснабжения, количество фаз, мощность; планы расположения оборудования', 'pending', 'Матрица АСУ: исходящее задание в ЭОМ.', 70],
    ['asu_base', 'out_ss', 'outgoing', 'АСУ', 'СС', 'Перечень оборудования автоматизации для подключения к ЛВС здания: количество портов, тип разъема', 'pending', 'Матрица АСУ: исходящее задание в СС.', 80],
    ['asu_base', 'out_sppz', 'outgoing', 'АСУ', 'СППЗ', 'Перечень оборудования автоматизации для обеспечения приема сигнала «Пожар» с указанием характеристик входного контакта: тип НЗ, рабочие напряжение и ток', 'pending', 'Матрица АСУ: исходящее задание в СППЗ.', 90],
    ['cable_trays', 'route_eom', 'incoming', 'ЭОМ', 'Лотки', 'Трассы силовых и питающих кабелей, требования к раздельной прокладке, габариты пучков и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от ЭОМ.', 10],
    ['cable_trays', 'route_ss', 'incoming', 'СС', 'Лотки', 'Трассы слаботочных кабелей, требования к ЛВС/связи, габариты пучков и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от СС.', 20],
    ['cable_trays', 'route_asu', 'incoming', 'АСУ', 'Лотки', 'Трассы кабелей автоматизации и диспетчеризации, требования к раздельной прокладке и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от АСУ.', 30],
    ['cable_trays', 'route_sppz', 'incoming', 'СППЗ', 'Лотки', 'Трассы кабелей пожарной автоматики/сигнализации, требования к огнестойкости и раздельной прокладке для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от СППЗ.', 40],
    ['cable_trays', 'out_common', 'outgoing', 'Лотки', 'ЭОМ/СС/АСУ/СППЗ', 'Сводные планы и сечения кабельных лотков для проверки трасс, заполнения, резервов и конфликтов прокладки', 'pending', 'Матрица лотков: исходящее сводное задание смежникам на проверку.', 50],
];
$templateItemStmt = $pdo->prepare('
    INSERT OR IGNORE INTO exchange_template_items (template_set_id, item_code, direction, from_section, to_section, assignment, default_status, comments, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
');
foreach ($exchangeTemplateItems as $item) {
    $setCode = array_shift($item);
    if (!isset($templateIds[$setCode])) {
        continue;
    }
    $templateItemStmt->execute([$templateIds[$setCode], ...$item]);
}

(new SbcCatalogService())->importBundled($pdo);

echo "SQLite ready: {$path}\n";
echo "Login: admin@example.local / {$password}\n";
