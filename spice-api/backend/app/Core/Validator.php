<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;

/**
 * Rule-string validator.
 *
 * Usage:
 *   $data = Validator::make($request->all(), [
 *       'mobile'   => 'required|mobile_in',
 *       'password' => 'required|string|min:8|max:72',
 *   ]);
 *
 * Returns only the validated keys, so nothing unexpected reaches a service.
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function make(array $data, array $rules): array
    {
        $validator = new self($data, $rules);
        $validator->run();

        if ($validator->errors !== []) {
            throw new ValidationException($validator->errors);
        }

        return $validator->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $isRequired = in_array('required', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($isEmpty) {
                if ($isRequired) {
                    $this->addError($field, 'The ' . $this->label($field) . ' field is required.');

                    continue;
                }

                if (in_array('nullable', $rules, true) || array_key_exists($field, $this->data)) {
                    $this->validated[$field] = null;
                }

                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $value = $this->applyRule($field, $name, $parameter, $value);
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function applyRule(string $field, string $rule, ?string $parameter, mixed $value): mixed
    {
        $label = $this->label($field);

        switch ($rule) {
            case 'string':
                if (!is_scalar($value)) {
                    $this->addError($field, "The {$label} must be a string.");

                    break;
                }

                return (string) $value;

            case 'int':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                    $this->addError($field, "The {$label} must be an integer.");

                    break;
                }

                return (int) $value;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "The {$label} must be a number.");

                    break;
                }

                return $value + 0;

            case 'boolean':
                $normalised = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($normalised === null) {
                    $this->addError($field, "The {$label} must be true or false.");

                    break;
                }

                return $normalised;

            case 'email':
                if (filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, "The {$label} must be a valid email address.");

                    break;
                }

                return strtolower((string) $value);

            case 'mobile_in':
                // Indian mobile: optional +91/91/0 prefix, then 6-9 followed by 9 digits.
                $digits = preg_replace('/\D/', '', (string) $value) ?? '';

                // Strip a country code or trunk prefix ONLY when one is really
                // there, which means only when the number is too long without it.
                //
                // Stripping '91' unconditionally rejects every legitimate number
                // that simply begins with 91: 9123456789 is a valid Indian mobile
                // and would be mangled into 8 digits. That is roughly 1% of
                // Indian numbers locked out of registration, login, OTP and
                // password reset — a silent failure for those customers, since
                // the error message insists their own number is invalid.
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2);
                } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                    $digits = substr($digits, 1);
                }

                if (preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
                    $this->addError($field, "The {$label} must be a valid 10-digit Indian mobile number.");

                    break;
                }

                return $digits;

            case 'uuid':
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $value) !== 1) {
                    $this->addError($field, "The {$label} must be a valid UUID.");
                }

                break;

            case 'slug':
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) !== 1) {
                    $this->addError(
                        $field,
                        "The {$label} may contain only lowercase letters, numbers and single hyphens."
                    );

                    break;
                }

                return strtolower((string) $value);

            case 'digits':
                $expected = (int) $parameter;

                if (preg_match('/^\d{' . $expected . '}$/', (string) $value) !== 1) {
                    $this->addError($field, "The {$label} must be exactly {$expected} digits.");
                }

                break;

            case 'min':
                $min = (float) $parameter;

                // An array is measured by how many items it holds. Without this
                // the array is cast to the string "Array", which both raises a
                // conversion warning and silently passes any length check —
                // `required|array|min:1` is a natural rule to write and used to
                // 500 the request rather than validate it.
                if (is_array($value)) {
                    if (count($value) < $min) {
                        $this->addError($field, "The {$label} must have at least {$parameter} item(s).");
                    }

                    break;
                }

                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value < $min) {
                        $this->addError($field, "The {$label} must be at least {$parameter}.");
                    }

                    break;
                }

                if (mb_strlen((string) $value) < $min) {
                    $this->addError($field, "The {$label} must be at least {$parameter} characters.");
                }

                break;

            case 'max':
                $max = (float) $parameter;

                if (is_array($value)) {
                    if (count($value) > $max) {
                        $this->addError($field, "The {$label} may not have more than {$parameter} item(s).");
                    }

                    break;
                }

                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value > $max) {
                        $this->addError($field, "The {$label} may not be greater than {$parameter}.");
                    }

                    break;
                }

                if (mb_strlen((string) $value) > $max) {
                    $this->addError($field, "The {$label} may not be longer than {$parameter} characters.");
                }

                break;

            case 'in':
                $allowed = explode(',', (string) $parameter);

                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "The selected {$label} is invalid.");
                }

                break;

            case 'regex':
                if (preg_match('/' . str_replace('/', '\/', (string) $parameter) . '/', (string) $value) !== 1) {
                    $this->addError($field, "The {$label} format is invalid.");
                }

                break;

            case 'password':
                $password = (string) $value;

                if (
                    mb_strlen($password) < 8
                    || preg_match('/[A-Za-z]/', $password) !== 1
                    || preg_match('/\d/', $password) !== 1
                ) {
                    $this->addError(
                        $field,
                        "The {$label} must be at least 8 characters and contain both a letter and a number."
                    );
                }

                break;

            case 'date':
                if (strtotime((string) $value) === false) {
                    $this->addError($field, "The {$label} must be a valid date.");
                }

                break;

            case 'array':
                if (!is_array($value)) {
                    $this->addError($field, "The {$label} must be an array.");
                }

                break;

            default:
                throw new \InvalidArgumentException("Unknown validation rule: {$rule}");
        }

        return $value;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }
}
