<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

class PageBuilderStyleService
{
    public function defaultCss(): string
    {
        return <<<CSS
.pb-page-builder {
    width: 100%;
    box-sizing: border-box;
}

.pb-page-builder *,
.pb-page-builder *::before,
.pb-page-builder *::after {
    box-sizing: border-box;
}

.pb-section {
    width: 100%;
    padding: 40px 20px;
    position: relative;
}

.pb-row {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
}

.pb-column {
    flex: 1 1 0;
    min-width: 0;
}

.pb-widget {
    margin-bottom: 16px;
}

.pb-heading {
    margin: 0 0 16px;
    line-height: 1.2;
}

.pb-text {
    line-height: 1.7;
}

.pb-button {
    display: inline-block;
    padding: 12px 24px;
    border-radius: 6px;
    text-decoration: none;
    background: #111827;
    color: #ffffff;
    font-weight: 600;
}

.pb-image {
    max-width: 100%;
    height: auto;
    display: block;
}

.pb-gallery-item {
    margin: 0;
    overflow: hidden;
    border-radius: 8px;
}

.pb-gallery-item img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
}

.pb-repeater-item {
    padding: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #ffffff;
}

.pb-taxonomy-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pb-taxonomy-badge {
    display: inline-flex;
    padding: 6px 12px;
    border-radius: 999px;
    background: #f3f4f6;
    color: #111827;
    font-size: 14px;
}

.pb-taxonomy-list {
    padding-left: 20px;
}

.pb-html {
    width: 100%;
}

.pb-widget-error {
    padding: 12px;
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
    border-radius: 6px;
    font-size: 14px;
}

@media (max-width: 768px) {
    .pb-section {
        padding: 28px 16px;
    }

    .pb-row {
        flex-direction: column;
        gap: 16px;
    }

    .pb-column {
        width: 100% !important;
    }

    .pb-gallery,
    .pb-repeater-grid {
        grid-template-columns: 1fr !important;
    }
}
CSS;
    }

    public function styleTag(): string
    {
        return '<style>' . $this->defaultCss() . '</style>';
    }

    public function wrapHtml(string $html): string
    {
        return '<div class="pb-page-builder">' . $html . '</div>';
    }

    public function renderFullHtml(string $html): string
    {
        return $this->styleTag() . $this->wrapHtml($html);
    }
}