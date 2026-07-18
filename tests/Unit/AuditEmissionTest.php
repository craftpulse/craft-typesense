<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pins Typesense's craft-audit-kit emission: a representative action per group
 * records exactly one correctly-named event on the kit bus; key and credential
 * values never reach the recorded details (the per-event allowlist strips
 * anything off-contract); and a shared service seam (suspend / resume) emits
 * with a null actor, the attribution a console operation carries. Assertions run
 * against a capturing sink registered on the bus, so they hold whether or not a
 * recorder (Ledger) is installed.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\AuditKit;
use craftpulse\typesense\console\controllers\SyncController;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;

/**
 * A bus sink that records every event it receives, for assertions.
 */
function auditCaptor(): AuditSinkInterface
{
    return new class() implements AuditSinkInterface {
        /** @var AuditEvent[] */
        public array $events = [];

        public function handle(AuditEvent $event): void
        {
            $this->events[] = $event;
        }
    };
}

/**
 * Installs a fresh capturing sink on the kit bus, runs the callback with it, and
 * restores the bus's original sinks afterwards.
 *
 * @param callable(object): void $test
 */
function withCaptor(callable $test): void
{
    $bus = AuditKit::$plugin->getBus();
    $original = $bus->getSinks();
    $captor = auditCaptor();
    $bus->setSinks([$captor]);

    try {
        $test($captor);
    } finally {
        $bus->setSinks($original);
    }
}

it('records exactly one correctly-named event on the bus', function() {
    withCaptor(function($captor) {
        Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_SYNCED, ['scope' => 'all']);

        expect($captor->events)->toHaveCount(1);

        $event = $captor->events[0];
        expect($event->name)->toBe('typesense.index.synced');
        expect($event->category)->toBe(Audit::CATEGORY_SYSTEM);
        expect($event->emitter)->toBe('typesense');
        expect($event->outcome)->toBe(AuditEvent::OUTCOME_SUCCESS);
        expect($event->details)->toBe(['scope' => 'all']);
    });
});

it('registers an event type for every event it emits', function() {
    $registry = AuditKit::$plugin->getEventTypes();

    $names = [
        Audit::EVENT_KEY_CREATED,
        Audit::EVENT_KEY_SCOPED_GENERATED,
        Audit::EVENT_AI_PROVIDER_SAVED,
        Audit::EVENT_AI_MODEL_PUSHED,
        Audit::EVENT_COLLECTION_SAVED,
        Audit::EVENT_MAPPING_SAVED,
        Audit::EVENT_SCHEMA_APPLIED,
        Audit::EVENT_CONFIG_APPLIED,
        Audit::EVENT_INDEX_FLUSHED,
        Audit::EVENT_INDEX_SUSPENDED,
        Audit::EVENT_ALIAS_CLONED,
        Audit::EVENT_CURATION_SAVED,
        Audit::EVENT_SYNONYMS_SAVED,
        Audit::EVENT_DICTIONARY_SAVED,
        Audit::EVENT_RELEVANCE_SAVED,
        Audit::EVENT_SNAPSHOT_CREATED,
        Audit::EVENT_SETTINGS_SAVED,
    ];

    foreach ($names as $name) {
        $type = $registry->getEventType($name);
        expect($type)->not->toBeNull("event type {$name} is not registered");
        expect($type->category)->toBe(Audit::CATEGORY_SYSTEM);
    }
});

it('never lets a key or credential value survive into recorded details', function() {
    $registry = AuditKit::$plugin->getEventTypes();

    // The key-created type permits only ids, labels, and scope descriptors: a
    // leaked `value` (the show-once secret) or resolved credential is stripped.
    $keyType = $registry->getEventType(Audit::EVENT_KEY_CREATED);
    $sanitized = $registry->sanitizeDetails($keyType, [
        'keyId' => 7,
        'label' => 'Search key',
        'value' => 'super-secret-key-value',
        'credentials' => 'sk-live-abc123',
    ]);

    expect($sanitized)->toBe(['keyId' => 7, 'label' => 'Search key']);
    expect($sanitized)->not->toHaveKey('value');
    expect($sanitized)->not->toHaveKey('credentials');

    // The scoped-key type carries no secret-bearing key at all.
    $scopedType = $registry->getEventType(Audit::EVENT_KEY_SCOPED_GENERATED);
    expect($scopedType->allowedDetailKeys)->toBe(['parameterCount']);
});

it('stamps the acting user, and null for a system or console actor', function() {
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_audit_{$suffix}";
    $admin->email = "ts_audit_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    $audit = Typesense::$plugin->getAudit();

    // No web identity (the state every console and queue request is in): null.
    expect($audit->resolveActorId())->toBeNull();

    // A logged-in control-panel user is attributed.
    Craft::$app->getUser()->setIdentity($admin);
    expect($audit->resolveActorId())->toBe($admin->id);
    Craft::$app->getUser()->setIdentity(null);
});

it('emits from the shared suspend and resume service seam with a null actor', function() {
    withCaptor(function($captor) {
        $suffix = bin2hex(random_bytes(4));
        $collection = "audit_col_{$suffix}";

        // The same service method the console suspend/resume commands call, so a
        // passing assertion here is proof the console path emits too.
        Typesense::$plugin->getSyncSuspend()->suspend($collection);
        Typesense::$plugin->getSyncSuspend()->resume($collection);

        expect($captor->events)->toHaveCount(2);
        expect($captor->events[0]->name)->toBe('typesense.index.suspended');
        expect($captor->events[0]->details)->toBe(['collection' => $collection]);
        expect($captor->events[0]->actorId)->toBeNull();
        expect($captor->events[1]->name)->toBe('typesense.index.resumed');
        expect($captor->events[1]->details)->toBe(['collection' => $collection]);
    });
});

it('emits index.synced from a console command action with a null actor', function() {
    withCaptor(function($captor) {
        // Invoke the console controller action directly: no web identity is
        // acting, so the recorded event is attributed to the system (null).
        $controller = new SyncController('sync', Typesense::$plugin);
        $controller->actionAll();

        $synced = array_values(array_filter(
            $captor->events,
            static fn(AuditEvent $event): bool => $event->name === 'typesense.index.synced',
        ));

        expect($synced)->toHaveCount(1);
        expect($synced[0]->details)->toBe(['scope' => 'all']);
        expect($synced[0]->actorId)->toBeNull();
    });
});
