<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Fuzzer;

enum MutationType: string
{
    case BASELINE_VALID = 'BASELINE_VALID';
    case TYPE_MISMATCH = 'TYPE_MISMATCH';
    case BOUNDARY_LIMIT = 'BOUNDARY_LIMIT';
    case MISSING_REQUIRED = 'MISSING_REQUIRED';
    case UNEXPECTED_PROPERTY = 'UNEXPECTED_PROPERTY';
    case MALFORMED_AUTH = 'MALFORMED_AUTH';
    case PARAMETER_INJECTION = 'PARAMETER_INJECTION';

    public function label(): string
    {
        return match ($this) {
            self::BASELINE_VALID => 'Baseline Contract',
            self::TYPE_MISMATCH => 'Type Mismatch Injection',
            self::BOUNDARY_LIMIT => 'Boundary Limit Fuzzing',
            self::MISSING_REQUIRED => 'Missing Required Field',
            self::UNEXPECTED_PROPERTY => 'Unexpected Property Injection',
            self::MALFORMED_AUTH => 'Malformed / Expired Auth',
            self::PARAMETER_INJECTION => 'Parameter Boundary Injection',
        };
    }
}
