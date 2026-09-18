<?php

declare(strict_types=1);

namespace App\Services;

final class RoleService
{
    public const ENGINEER = 'engineer';
    public const CHIEF_SPECIALIST = 'chief_specialist';
    public const GROUP_LEAD = 'group_lead';
    public const DEPARTMENT_HEAD = 'department_head';
    public const DEPUTY_DEPARTMENT_HEAD = 'deputy_department_head';
    public const GIP = 'gip';
    public const BIM_MANAGER = 'bim_manager';
    public const PROJECT_MANAGER = 'project_manager';
    public const DEPUTY_DIRECTOR = 'deputy_director';
    public const ADJACENT_DIRECTOR = 'adjacent_director';
    public const HR = 'hr';
    public const DIRECTOR = 'director';
    public const ADMIN = 'admin';

    public const DESIGNER = self::ENGINEER;
    public const LEAD = self::GROUP_LEAD;
    public const HEAD = self::DEPARTMENT_HEAD;

    public const CAP_LOCIA = 'locia';
    public const CAP_PROJECTS = 'projects';
    public const CAP_PROJECTS_ALL = 'projects_all';
    public const CAP_PROJECTS_CREATE = 'projects_create';
    public const CAP_TASKS_EDIT_ALL = 'tasks_edit_all';
    public const CAP_DPR = 'dpr';
    public const CAP_REPORTS = 'reports';
    public const CAP_INTEGRATIONS = 'integrations';
    public const CAP_USERS = 'users';
    public const CAP_SETTINGS = 'settings';
    public const CAP_DELETE = 'delete';
    public const CAP_COMPETENCY = 'competency';
    public const CAP_BIM = 'bim';
    public const CAP_HR = 'hr';

    private static array $capabilityCache = [];
    private static array $positionCache = [];

    private const MODEL = [
        self::ENGINEER => [
            'label' => 'Инженер',
            'sidebar' => 'Инженер',
            'home' => '/locia',
            'scope' => 'Свои задачи на выполнение без постановки новых задач',
            'capabilities' => [
                self::CAP_LOCIA,
            ],
        ],
        self::CHIEF_SPECIALIST => [
            'label' => 'Главный специалист',
            'sidebar' => 'Главный специалист',
            'home' => '/locia',
            'scope' => 'Свои задачи, постановка задач и приёмка работ по назначению',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
            ],
        ],
        self::GROUP_LEAD => [
            'label' => 'Руководитель группы',
            'sidebar' => 'Руководитель группы',
            'home' => '/locia',
            'scope' => 'Задачи, где он автор, исполнитель или проверяющий; приёмка работ группы',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
            ],
        ],
        self::DEPARTMENT_HEAD => [
            'label' => 'Руководитель отдела',
            'sidebar' => 'Руководитель отдела',
            'home' => '/shturman',
            'scope' => 'Задачи отдела, связанные проекты, ДПР и финальные расчёты без ставок',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::DEPUTY_DEPARTMENT_HEAD => [
            'label' => 'Зам. начальника отдела',
            'sidebar' => 'Зам. начальника отдела',
            'home' => '/shturman',
            'scope' => 'Задачи отдела, связанные проекты, ДПР и финальные расчёты без ставок',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::GIP => [
            'label' => 'ГИП',
            'sidebar' => 'ГИП',
            'home' => '/shturman',
            'scope' => 'Свои проекты, задачи, предпроекты и ГИП-согласования',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::BIM_MANAGER => [
            'label' => 'BIM-менеджер',
            'sidebar' => 'BIM-менеджер',
            'home' => '/shturman',
            'scope' => 'Модели, Просмотр ТИМ и BIM-задачи по проектам; может самосогласовывать собственные выдачи',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_ALL,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_REPORTS,
                self::CAP_BIM,
            ],
        ],
        self::PROJECT_MANAGER => [
            'label' => 'Руководитель проекта',
            'sidebar' => 'Руководитель проекта',
            'home' => '/shturman',
            'scope' => 'Свои проекты и проектные задачи в зоне ответственности',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::DEPUTY_DIRECTOR => [
            'label' => 'Зам. директора',
            'sidebar' => 'Зам. директора',
            'home' => '/shturman',
            'scope' => 'Все проекты, задачи и финальные расчёты без доступа к ставкам',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_ALL,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::ADJACENT_DIRECTOR => [
            'label' => 'Директор смежников',
            'sidebar' => 'Директор смежников',
            'home' => '/shturman',
            'scope' => 'Директор внешнего участника: все проекты, задачи и финальные утверждения без администрирования и ставок',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_ALL,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
            ],
        ],
        self::HR => [
            'label' => 'HR',
            'sidebar' => 'HR',
            'home' => '/hr',
            'scope' => 'HR-раздел, структура и performance review без ставок, ФОТ и мотивационных сумм',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_HR,
            ],
        ],
        self::DIRECTOR => [
            'label' => 'Директор',
            'sidebar' => 'Директор',
            'home' => '/shturman',
            'scope' => 'Все проекты, задачи, финальные утверждения, ставки сотрудников и администрирование',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_ALL,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
                self::CAP_USERS,
                self::CAP_SETTINGS,
                self::CAP_DELETE,
                self::CAP_COMPETENCY,
                self::CAP_BIM,
                self::CAP_HR,
            ],
        ],
        self::ADMIN => [
            'label' => 'Администратор',
            'sidebar' => 'Администратор',
            'home' => '/shturman',
            'scope' => 'Все проекты, задачи, финальные утверждения, ставки сотрудников и администрирование',
            'capabilities' => [
                self::CAP_LOCIA,
                self::CAP_PROJECTS,
                self::CAP_PROJECTS_ALL,
                self::CAP_PROJECTS_CREATE,
                self::CAP_TASKS_EDIT_ALL,
                self::CAP_DPR,
                self::CAP_REPORTS,
                self::CAP_INTEGRATIONS,
                self::CAP_USERS,
                self::CAP_SETTINGS,
                self::CAP_DELETE,
                self::CAP_COMPETENCY,
                self::CAP_BIM,
                self::CAP_HR,
            ],
        ],
    ];

    private const ALIASES = [
        'designer' => self::ENGINEER,
        'lead' => self::GROUP_LEAD,
        'head' => self::DEPARTMENT_HEAD,
    ];

    private const LEVELS = [
        self::ENGINEER => 10,
        self::CHIEF_SPECIALIST => 20,
        self::GROUP_LEAD => 30,
        self::DEPARTMENT_HEAD => 40,
        self::DEPUTY_DEPARTMENT_HEAD => 40,
        self::GIP => 50,
        self::BIM_MANAGER => 35,
        self::PROJECT_MANAGER => 50,
        self::DEPUTY_DIRECTOR => 60,
        self::ADJACENT_DIRECTOR => 70,
        self::HR => 45,
        self::DIRECTOR => 70,
        self::ADMIN => 70,
    ];

    private const ROLE_EQUIVALENTS = [
        self::ADMIN => [self::DIRECTOR],
        self::DIRECTOR => [self::ADMIN, self::ADJACENT_DIRECTOR],
        self::GIP => [self::PROJECT_MANAGER],
        self::PROJECT_MANAGER => [self::GIP],
    ];

    public static function all(): array
    {
        $model = self::MODEL;
        foreach (array_keys($model) as $role) {
            $model[$role]['capabilities'] = self::capabilities($role);
        }

        return $model;
    }

    public static function roles(): array
    {
        return array_keys(self::MODEL);
    }

    public static function exists(?string $role): bool
    {
        $raw = self::alias((string) $role);
        return isset(self::MODEL[$raw]) || self::positionDefinition($raw) !== null;
    }

    public static function label(?string $role): string
    {
        $raw = self::alias((string) $role);
        $position = isset(self::MODEL[$raw]) ? null : self::positionDefinition($raw);
        return (string) ($position['title'] ?? self::MODEL[self::normalize($raw)]['label'] ?? $role);
    }

    public static function sidebarLabel(?string $role): string
    {
        return self::label($role);
    }

    public static function homePath(?string $role): string
    {
        return self::MODEL[self::normalize($role)]['home'] ?? '/locia';
    }

    public static function scope(?string $role): string
    {
        return self::MODEL[self::normalize($role)]['scope'] ?? '';
    }

    public static function has(?string $role, string $capability): bool
    {
        return in_array($capability, self::capabilities($role), true);
    }

    public static function capabilities(?string $role): array
    {
        $raw = self::alias((string) $role);
        if (isset(self::$capabilityCache[$raw])) {
            return self::$capabilityCache[$raw];
        }

        $position = self::positionDefinition($raw);
        if ($position !== null) {
            $baseRole = self::alias((string) ($position['base_role'] ?? self::ENGINEER));
            if ($baseRole === self::DIRECTOR) {
                return self::$capabilityCache[$raw] = self::capabilityKeys();
            }
            $storedPosition = self::storedPositionCapabilities((int) $position['id']);
            if ($storedPosition !== null) {
                return self::$capabilityCache[$raw] = $storedPosition;
            }
            $storedBase = self::storedCapabilities($baseRole);
            if ($storedBase !== null) {
                return self::$capabilityCache[$raw] = $storedBase;
            }
            return self::$capabilityCache[$raw] = self::defaultCapabilities($baseRole);
        }

        $role = self::normalize($raw);
        $stored = self::storedCapabilities($role);
        if ($stored !== null) {
            self::$capabilityCache[$role] = $stored;

            return $stored;
        }

        self::$capabilityCache[$role] = self::defaultCapabilities($role);

        return self::$capabilityCache[$role];
    }

    public static function defaultCapabilities(?string $role): array
    {
        return self::MODEL[self::normalize($role)]['capabilities'] ?? [];
    }

    public static function capabilityLabels(): array
    {
        return [
            self::CAP_LOCIA => 'Лемма',
            self::CAP_PROJECTS => 'Проекты',
            self::CAP_PROJECTS_ALL => 'Все проекты',
            self::CAP_PROJECTS_CREATE => 'Создание проектов',
            self::CAP_TASKS_EDIT_ALL => 'Все задачи',
            self::CAP_DPR => 'ДПР',
            self::CAP_REPORTS => 'Отчёты',
            self::CAP_INTEGRATIONS => 'Интеграции',
            self::CAP_USERS => 'Пользователи',
            self::CAP_SETTINGS => 'Настройки',
            self::CAP_DELETE => 'Удаление',
            self::CAP_COMPETENCY => 'Матрица компетенций',
            self::CAP_BIM => 'Модели / Просмотр ТИМ',
            self::CAP_HR => 'HR',
        ];
    }

    public static function capabilityKeys(): array
    {
        return array_keys(self::capabilityLabels());
    }

    public static function accessSyncGroups(): array
    {
        return [
            [self::DIRECTOR, self::ADMIN],
            [self::GIP, self::PROJECT_MANAGER],
        ];
    }

    public static function normalizeAccessMatrix(array $matrix): array
    {
        $normalized = [];
        foreach (self::roles() as $role) {
            foreach (self::capabilityKeys() as $capability) {
                $normalized[$role][$capability] = !empty($matrix[$role][$capability]);
            }
        }

        foreach (self::accessSyncGroups() as $group) {
            foreach (self::capabilityKeys() as $capability) {
                $enabled = false;
                foreach ($group as $role) {
                    $enabled = $enabled || !empty($normalized[$role][$capability]);
                }
                foreach ($group as $role) {
                    $normalized[$role][$capability] = $enabled;
                }
            }
        }

        return $normalized;
    }

    public static function normalize(?string $role): string
    {
        $role = self::alias((string) $role);
        if (isset(self::MODEL[$role])) {
            return $role;
        }
        $position = self::positionDefinition($role);
        return $position !== null ? self::alias((string) ($position['base_role'] ?? self::ENGINEER)) : $role;
    }

    public static function level(?string $role): int
    {
        return self::LEVELS[self::normalize($role)] ?? 0;
    }

    public static function atLeast(?string $role, string $minimumRole): bool
    {
        $minimumLevel = self::level($minimumRole);
        return $minimumLevel > 0 && self::level($role) >= $minimumLevel;
    }

    public static function isAny(?string $role, array $roles): bool
    {
        $role = self::normalize($role);
        $roles = self::expandRoles($roles);

        return in_array($role, $roles, true);
    }

    private static function storedCapabilities(string $role): ?array
    {
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare('SELECT capability, enabled FROM role_access_permissions WHERE role = ?');
            $stmt->execute([$role]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $capabilities = [];
        foreach ($rows as $row) {
            if ((int) ($row['enabled'] ?? 0) === 1) {
                $capabilities[] = (string) $row['capability'];
            }
        }

        return array_values(array_intersect(self::capabilityKeys(), $capabilities));
    }

    public static function resetCache(): void
    {
        self::$capabilityCache = [];
        self::$positionCache = [];
    }

    private static function alias(string $role): string
    {
        return self::ALIASES[$role] ?? $role;
    }

    private static function positionDefinition(string $role): ?array
    {
        if ($role === '' || $role === self::ADMIN) {
            return null;
        }
        if (array_key_exists($role, self::$positionCache)) {
            return self::$positionCache[$role] ?: null;
        }
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare('SELECT id, role_key, base_role, title, is_active FROM positions WHERE role_key = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$role]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            $row = false;
        }
        self::$positionCache[$role] = $row ?: false;
        return $row ?: null;
    }

    private static function storedPositionCapabilities(int $positionId): ?array
    {
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare('SELECT capability, enabled FROM position_access_permissions WHERE position_id = ?');
            $stmt->execute([$positionId]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $capabilities = [];
        foreach ($rows as $row) {
            if ((int) ($row['enabled'] ?? 0) === 1) {
                $capabilities[] = (string) $row['capability'];
            }
        }
        return array_values(array_intersect(self::capabilityKeys(), $capabilities));
    }

    private static function expandRoles(array $roles): array
    {
        $expanded = [];
        foreach ($roles as $candidate) {
            $role = self::normalize((string) $candidate);
            $expanded[] = $role;
            foreach (self::ROLE_EQUIVALENTS[$role] ?? [] as $equivalent) {
                $expanded[] = $equivalent;
            }
        }

        return array_values(array_unique($expanded));
    }
}
