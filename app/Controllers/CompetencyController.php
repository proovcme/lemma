<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;

final class CompetencyController extends BaseController
{
    public function index(): void
    {
        if (app_is_demo_mode()) {
            http_response_code(404);
            view('layouts/error', ['title' => 'Страница не найдена', 'message' => 'Раздел недоступен в демо.']);
            return;
        }

        $user = require_auth();
        if (!PermissionService::canOpenCompetency($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Матрица компетенций доступна по праву «Матрица компетенций» (настраивается в Доступах).']);
            return;
        }

        $matrix = require BASE_PATH . '/config/competency_matrix.php';

        $this->render('reference/competencies', [
            'title' => 'Матрица компетенций',
            'subtitle' => 'Требуемые уровни компетенций по должностям и грейдам (read-only справочник)',
            'matrix' => $matrix,
        ]);
    }
}
