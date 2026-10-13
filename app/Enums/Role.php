<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Tutor = 'tutor';
    case Student = 'student';
    case Guardian = 'guardian';
}
