<?php

declare(strict_types=1);

namespace App\Controllers;

final class ManualController extends BaseController
{
    public function index(): void
    {
        require_auth();

        $this->render('manual/index', [
            'title' => 'Руководство',
            'subtitle' => app_demo_mask_text('Как работать в Лемме, Штурмане и проектных таблицах'),
            'regulations' => require BASE_PATH . '/config/regulations.php',
        ]);
    }

    public function regulation(): void
    {
        require_auth();

        $regulation = require BASE_PATH . '/config/work_regulation.php';
        $this->render('manual/regulation', [
            'title' => app_demo_mask_text((string) ($regulation['title'] ?? 'Регламент работы в Лемме')),
            'subtitle' => app_demo_mask_text((string) ($regulation['subtitle'] ?? '')),
            'regulation' => $regulation,
        ]);
    }
}
