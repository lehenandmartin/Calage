<?php
/** Error message below a field: field_error($errors, 'key'). */
if (!function_exists('field_error')) {
    function field_error(array $errors, string $key): string
    {
        return isset($errors[$key])
            ? '<p class="field-error" id="error-' . e(str_replace('.', '-', $key)) . '">' . e($errors[$key]) . '</p>'
            : '';
    }
    function invalid(array $errors, string $key): string
    {
        return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="error-' . e(str_replace('.', '-', $key)) . '"' : '';
    }
}
