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
            'book-open' => '<path d="M12 7v14"></path><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"></path>',
            'download' => '<path d="M12 15V3"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5 5 5-5"></path>',
            'ellipsis' => '<circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle>',
            'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path>',
            'file-plus' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M12 18v-6"></path><path d="M9 15h6"></path>',
            'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path>',
            'info' => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
            'plus' => '<path d="M5 12h14"></path><path d="M12 5v14"></path>',
            'puzzle' => '<path d="M15.39 4.39a1.5 1.5 0 0 0 2.12 2.12l1.27-1.27a1 1 0 0 1 1.7.71V9a1 1 0 0 1-1 1h-3.05a1 1 0 0 0-.71 1.7l1.27 1.27a1.5 1.5 0 0 1-2.12 2.12l-1.27-1.27a1 1 0 0 0-1.7.71V18a1 1 0 0 1-1 1H7.85a1 1 0 0 1-.71-1.7l1.27-1.27a1.5 1.5 0 1 0-2.12-2.12l-1.27 1.27a1 1 0 0 1-1.7-.71V11a1 1 0 0 1 1-1h3.05a1 1 0 0 0 .71-1.7L6.81 7.03a1.5 1.5 0 0 1 2.12-2.12l1.27 1.27a1 1 0 0 0 1.7-.71V2a1 1 0 0 1 1-1h3.05a1 1 0 0 1 .71 1.7z"></path>',
            'search' => '<path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle>',
            'sidebar' => '<rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M9 3v18"></path>',
            'sparkles' => '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle cx="4" cy="20" r="1"></circle>',
            'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831 2.34 2.34 0 0 1 2.33-4.033 2.34 2.34 0 0 0 3.319-1.915"></path><circle cx="12" cy="12" r="3"></circle>',
            'trash-2' => '<path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>',
            'x' => '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
        ];

        return '<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">' . ($icons[$name] ?? $icons['arrow-up']) . '</svg>';
    }
}
