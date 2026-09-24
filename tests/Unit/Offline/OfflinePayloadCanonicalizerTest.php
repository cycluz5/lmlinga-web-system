<?php

namespace Tests\Unit\Offline;

use App\Support\Offline\OfflineOperationType;
use App\Support\Offline\OfflinePayloadCanonicalizer;
use Tests\TestCase;

class OfflinePayloadCanonicalizerTest extends TestCase
{
    public function test_associative_keys_are_sorted_recursively(): void
    {
        $left = OfflinePayloadCanonicalizer::json([
            'b' => 1,
            'a' => ['z' => 2, 'm' => 3],
        ]);
        $right = OfflinePayloadCanonicalizer::json([
            'a' => ['m' => 3, 'z' => 2],
            'b' => 1,
        ]);

        $this->assertSame($left, $right);
        $this->assertSame('{"a":{"m":3,"z":2},"b":1}', $left);
    }

    public function test_sequential_array_order_is_preserved(): void
    {
        $json = OfflinePayloadCanonicalizer::json([
            'tags' => ['b', 'a', 'c'],
        ]);

        $this->assertSame('{"tags":["b","a","c"]}', $json);
    }

    public function test_scalar_types_are_preserved(): void
    {
        $json = OfflinePayloadCanonicalizer::json([
            'count' => 3,
            'flag' => true,
            'label' => 'ok',
            'missing' => null,
            'ratio' => 1.5,
        ]);

        $this->assertSame(
            '{"count":3,"flag":true,"label":"ok","missing":null,"ratio":1.5}',
            $json
        );
    }

    public function test_utf8_is_not_escaped(): void
    {
        $json = OfflinePayloadCanonicalizer::json([
            'name' => 'Niña',
        ]);

        $this->assertSame('{"name":"Niña"}', $json);
    }

    public function test_hash_is_stable_across_retries(): void
    {
        $payload = [
            'zone' => 'Zone 2',
            'household_no' => '121',
            'street' => 'Layuan St.',
        ];

        $first = OfflinePayloadCanonicalizer::operationHash(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $payload
        );
        $second = OfflinePayloadCanonicalizer::operationHash(
            OfflineOperationType::HOUSEHOLD_CREATE,
            [
                'street' => 'Layuan St.',
                'household_no' => '121',
                'zone' => 'Zone 2',
            ]
        );

        $this->assertSame(64, strlen($first));
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_operation_type_is_included_in_the_hash(): void
    {
        $payload = ['household_no' => '121'];

        $create = OfflinePayloadCanonicalizer::operationHash(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $payload
        );
        $update = OfflinePayloadCanonicalizer::operationHash(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $payload
        );

        $this->assertNotSame($create, $update);
    }

    public function test_ignored_client_metadata_does_not_change_the_hash(): void
    {
        $base = ['household_no' => '121', 'zone' => 'Zone 1'];
        $noisy = $base + [
            'attempt_count' => 4,
            'last_attempt_at' => '2026-09-05T01:00:00Z',
            'client_status' => 'queued',
            'error_message' => 'timeout',
            'actor_user_id' => 99,
            'server_result' => ['id' => 1],
        ];

        $this->assertSame(
            OfflinePayloadCanonicalizer::operationHash(OfflineOperationType::HOUSEHOLD_CREATE, $base),
            OfflinePayloadCanonicalizer::operationHash(OfflineOperationType::HOUSEHOLD_CREATE, $noisy)
        );
    }

    public function test_payload_change_changes_the_hash(): void
    {
        $this->assertNotSame(
            OfflinePayloadCanonicalizer::operationHash(
                OfflineOperationType::HOUSEHOLD_CREATE,
                ['household_no' => '121']
            ),
            OfflinePayloadCanonicalizer::operationHash(
                OfflineOperationType::HOUSEHOLD_CREATE,
                ['household_no' => '122']
            )
        );
    }

    public function test_scalar_type_and_empty_shape_differences_change_the_hash(): void
    {
        $this->assertNotSame(
            OfflinePayloadCanonicalizer::hash(['n' => 1]),
            OfflinePayloadCanonicalizer::hash(['n' => '1'])
        );
        $this->assertNotSame(
            OfflinePayloadCanonicalizer::hash(['flag' => true]),
            OfflinePayloadCanonicalizer::hash(['flag' => 1])
        );
        $this->assertNotSame(
            OfflinePayloadCanonicalizer::hash(['note' => null]),
            OfflinePayloadCanonicalizer::hash(['note' => ''])
        );
        $this->assertNotSame(
            OfflinePayloadCanonicalizer::hash(['items' => []]),
            OfflinePayloadCanonicalizer::hash(['items' => new \stdClass])
        );
        $this->assertSame(
            '{"empty_list":[],"empty_object":{}}',
            OfflinePayloadCanonicalizer::json([
                'empty_object' => new \stdClass,
                'empty_list' => [],
            ])
        );
    }

    public function test_nested_arrays_and_reordered_lists_are_distinguished(): void
    {
        $nested = OfflinePayloadCanonicalizer::hash([
            'members' => [
                ['name' => 'Ana', 'tags' => ['a', 'b']],
                ['name' => 'Ben', 'tags' => ['c']],
            ],
        ]);
        $reorderedList = OfflinePayloadCanonicalizer::hash([
            'members' => [
                ['name' => 'Ben', 'tags' => ['c']],
                ['name' => 'Ana', 'tags' => ['a', 'b']],
            ],
        ]);
        $reorderedNestedKeys = OfflinePayloadCanonicalizer::hash([
            'members' => [
                ['tags' => ['a', 'b'], 'name' => 'Ana'],
                ['tags' => ['c'], 'name' => 'Ben'],
            ],
        ]);

        $this->assertNotSame($nested, $reorderedList);
        $this->assertSame($nested, $reorderedNestedKeys);
    }
}
