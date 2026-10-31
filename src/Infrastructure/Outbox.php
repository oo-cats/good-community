<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class Outbox
{
    public function __construct(private Db $db) {}
    public function emit(string $type, string $aggregate, int $version, array $payload, ?int $user = null): int
    {
        $key = Data::hash([$type,$aggregate,$version]);
        $hash = Data::hash($payload);
        $this->db->run('INSERT IGNORE INTO lgc_events(event_key,event_type,aggregate_key,aggregate_version,payload,payload_hash,subject_user_id,occurred_at) VALUES(?,?,?,?,?,?,?,?)', [$key,$type,$aggregate,$version,Data::json($payload),$hash,$user,Data::now()]);
        $event = $this->db->one('SELECT id,payload_hash FROM lgc_events WHERE event_key=?', [$key]);
        if ($event['payload_hash'] !== $hash) throw new DomainError('event_identity_conflict', 409);
        $this->enqueue('event', ['event_id'=>(int)$event['id']], 'event:'.$key, 0, (int)$event['id']);
        return (int)$event['id'];
    }
    public function enqueue(string $type, array $payload, string $identity, int $lane = 1, ?int $event = null, ?string $available=null): void
    {
        $now = Data::now();
        $this->db->run("INSERT IGNORE INTO lgc_jobs(job_key,lane,job_type,event_id,payload,status,available_at,created_at,updated_at) VALUES(?,?,?,?,?,'pending',?,?,?)", [hash('sha256',$identity),$lane,$type,$event,Data::json($payload),$available ?? $now,$now,$now]);
    }
}
