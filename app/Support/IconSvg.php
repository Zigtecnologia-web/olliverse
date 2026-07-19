<?php

declare(strict_types=1);

namespace App\Support;

final class IconSvg
{
    public static function send(): string
    {
        return self::render('arrow-up');
    }

    public static function render(string $name): string
    {
        $icons = [
            'arrow-up' => '<path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path>',
            'info' => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
            'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831 2.34 2.34 0 0 1 2.33-4.033 2.34 2.34 0 0 0 3.319-1.915"></path><circle cx="12" cy="12" r="3"></circle>',
            'x' => '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
        ];

        return '<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">' . ($icons[$name] ?? $icons['arrow-up']) . '</svg>';
    }
}
