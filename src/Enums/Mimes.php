<?php

namespace Clinically\PrismBedrock\Enums;

use Illuminate\Support\Str;

enum Mimes: string
{
    case Pdf = 'application/pdf';
    case Csv = 'text/csv';
    case Doc = 'application/msword';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Xls = 'application/vnd.ms-excel';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Html = 'text/html';
    case Txt = 'text/plain';
    case Md = 'text/markdown';
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Gif = 'image/gif';
    case Webp = 'image/webp';

    public function toExtension(): string
    {
        return Str::lower($this->name);
    }
}
