<?php

namespace Smoservice\Roskassa;

/**
 * API недоступно или ответило ошибкой. Это НЕ «платёж не оплачен»:
 * вызывающий код обязан отложить решение (сверка повторит попытку позже).
 */
final class ApiException extends \RuntimeException
{
}
