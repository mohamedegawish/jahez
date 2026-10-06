<?php

namespace App\Enums;

/**
 * Status of a transformation plan version (ADR-025). Only a draft is edited; publishing
 * it supersedes the previous published version, which stays readable as history.
 */
enum TransformationPlanVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
