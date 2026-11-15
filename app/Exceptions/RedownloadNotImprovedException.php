<?php
namespace App\Exceptions;

/**
 * 重新下载的结果仍被降级，已保留原文件；不应重试
 */
class RedownloadNotImprovedException extends \RuntimeException
{
}
