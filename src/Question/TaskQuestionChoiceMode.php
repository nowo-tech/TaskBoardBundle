<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): how many options an answer may pick — exactly one (`single`, or none with *Other*) or
 * any number (`multiple`, which needs options).
 */
enum TaskQuestionChoiceMode: string
{
    case Single   = 'single';
    case Multiple = 'multiple';
}
