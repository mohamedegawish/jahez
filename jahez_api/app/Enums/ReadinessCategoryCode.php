<?php

namespace App\Enums;

/**
 * Stable identifiers of the digital readiness categories (ADR-018). Code compares
 * these, never the display names. The score range of each category belongs to the
 * questionnaire version and is stored with it (readiness_categories).
 */
enum ReadinessCategoryCode: string
{
    case B4Automation = 'b4_automation';
    case Basic = 'basic';
    case Advanced = 'advanced';
    case Smart = 'smart';
}
