<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Currency;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};

/** The sole owner of money writes. No global currency-total row is updated. */
final class Ledger
{
    public function __construct(private Db $db, private Outbox $outbox) {}
    public function define(Actor $actor, array $input): array
    {
        $actor->assert('currency.manage');
        $name = Data::name((string)($input['name'] ?? ''));
        $kind = $input['resource_kind'] ?? 'currency';
        if (!in_array($kind, ['currency','experience'], true)) throw new DomainError('invalid_resource_kind');
        $rate = $kind === 'experience' ? [null,null] : [Data::integer($input['rate_numerator'] ?? '1'),Data::integer($input['rate_denominator'] ?? '1')];
        if ($rate[0] === '0' || $rate[1] === '0') throw new DomainError('invalid_rate');
        $icon = $input['icon'] ?? null;
        if ($icon !== null && (!is_string($icon) || strlen($icon) > 500 || !preg_match('~^(?:fa[sbr]? fa-[a-z0-9-]+|https://[^\s<>"\x27]+|/assets/[^\s<>"\x27]+)$~D', $icon))) throw new DomainError('invalid_icon');
        $id = $input['id'] ?? Data::uuid();
        $existing = $this->db->one('SELECT * FROM lgc_currencies WHERE public_id=?', [Data::bin($id)]);
        if (!$existing && isset($input['id'])) throw new DomainError('resource_not_found',404);
        if ($existing && $existing['resource_kind'] !== $kind) throw new DomainError('resource_kind_immutable',409);
        $now = Data::now();
        if ($existing) {
            if ((int)($input['expected_version'] ?? 0) !== (int)$existing['metadata_version']) throw new DomainError('version_conflict',409);
            $changed = $this->db->run('UPDATE lgc_currencies SET name=?,name_key=?,icon=?,rate_numerator=?,rate_denominator=?,metadata_version=metadata_version+1,updated_at=? WHERE id=? AND metadata_version=?', [$name,Data::nameKey($name),$icon === null ? null : Data::json($icon),...$rate,$now,$existing['id'],$existing['metadata_version']])->rowCount();
            if (!$changed) throw new DomainError('version_conflict',409);
        } else {
            $this->db->run("INSERT INTO lgc_currencies(public_id,name,name_key,icon,resource_kind,rate_numerator,rate_denominator,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'active',?,?)", [Data::bin($id),$name,Data::nameKey($name),$icon === null ? null : Data::json($icon),$kind,...$rate,$now,$now]);
        }
        return $this->get($id);
    }
    public function get(string $id): array
    {
        $row = $this->db->one('SELECT * FROM lgc_currencies WHERE public_id=?', [Data::bin($id)]);
        if (!$row) throw new DomainError('resource_not_found',404);
        return $row;
    }
    public function resources(Actor $actor, bool $includeTotals=true): array
    {
        $rows = $this->db->all('SELECT * FROM lgc_currencies ORDER BY id');
        $wallets = $this->db->all('SELECT currency_id,balance FROM lgc_wallets WHERE user_id=?', [$actor->id]);
        $balances = array_column($wallets,'balance','currency_id');
        $totals = $includeTotals && $actor->can('currency.manage') ? array_column($this->db->all('SELECT currency_id,CAST(SUM(balance) AS CHAR) total FROM lgc_wallets GROUP BY currency_id'),'total','currency_id') : [];
        return array_map(fn ($row) => ['id'=>Data::id($row['public_id']),'name'=>$row['name'],'icon'=>Data::decode($row['icon'],null),'resource_kind'=>$row['resource_kind'],'balance'=>(string)($balances[$row['id']] ?? '0'),'actual_supply'=>$includeTotals && $actor->can('currency.manage') ? (string)($totals[$row['id']] ?? '0') : null,'rate_numerator'=>$row['rate_numerator'],'rate_denominator'=>$row['rate_denominator'],'version'=>(int)$row['metadata_version']], $rows);
    }
    public function rewards(array $items): array
    {
        if (strlen(Data::json($items)) > 65536 || count($items) > 1000) throw new DomainError('reward_budget_exceeded');
        $normalized = [];
        foreach ($items as $item) {
            $id = strtolower((string)($item['currency_id'] ?? ''));
            Data::bin($id);
            if (isset($normalized[$id])) throw new DomainError('duplicate_resource');
            $delta = Data::integer($item['delta'] ?? null, true);
            $normalized[$id] = ['currency_id'=>$id,'delta'=>$delta];
        }
        ksort($normalized);
        return array_values(array_filter($normalized, fn ($item) => $item['delta'] !== '0'));
    }
    public function command(Actor $actor, array $input): array
    {
        $action = $input['action'] ?? '';
        $currency = $this->get((string)($input['currency_id'] ?? ''));
        $actor->assert(match ($action) { 'issue'=>$currency['resource_kind']==='experience' ? 'experience.issue' : 'currency.issue','deduct'=>'currency.deduct','transfer','tip'=>'currency.transfer', default=>throw new DomainError('invalid_money_action') });
        $target = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$target || !$this->db->scalar('SELECT id FROM '.$this->db->native('users').' WHERE id=?', [$target])) throw new DomainError('user_not_found',404);
        $amount = (int)Data::integer($input['amount'] ?? null);
        if (!$amount) throw new DomainError('positive_amount_required');
        if ($currency['resource_kind'] === 'experience') {
            if (in_array($action,['transfer','tip'],true)) throw new DomainError('experience_not_transferable');
            if ($action === 'issue') $actor->assert('experience.issue');
        }
        if (in_array($action,['transfer','tip'],true) && $target === $actor->id) throw new DomainError('same_wallet');
        $request = strtolower((string)($input['request_id'] ?? '')); Data::bin($request);
        $lines = match ($action) { 'issue'=>[[$currency['id'],$target,$amount]], 'deduct'=>[[$currency['id'],$target,-$amount]], default=>[[$currency['id'],$actor->id,-$amount],[$currency['id'],$target,$amount]] };
        return $this->post($actor->id, $action, hash('sha256','money:'.$actor->id.':'.$request), $lines, trim((string)($input['reason'] ?? '')) ?: (['tip'=>'帖子打赏','transfer'=>'账户转账','issue'=>'系统发放','deduct'=>'管理扣除'][$action]), null, isset($input['post_id']) ? (int)$input['post_id'] : null);
    }
    /** Trusted task service only; the HTTP adapter never exposes this entry point. */
    public function taskSettlement(int $user, int $funder, array $items, string $operation, string $source, string $reason='任务领取'): array
    {
        $lines = [];
        foreach ($this->rewards($items) as $item) {
            $currency = $this->get($item['currency_id']);
            if ($currency['state'] !== 'active') throw new DomainError('resource_unavailable',409);
            $delta = (int)$item['delta'];
            if ($delta > 0 && $currency['resource_kind'] === 'currency') {
                if ($user === $funder) throw new DomainError('self_funded_reward',409);
                $lines[] = [$currency['id'],$funder,-$delta];
            }
            $lines[] = [$currency['id'],$user,$delta];
        }
        return $this->post($user,'task_settlement',$operation,$lines,$reason,$source);
    }
    private function post(int $actor, string $kind, string $operation, array $lines, string $reason, ?string $source = null, ?int $post = null, ?int $reversal = null): array
    {
        if (mb_strlen($reason) > 1000) throw new DomainError('reason_too_long');
        usort($lines, fn ($a,$b) => [$a[0],$a[1]] <=> [$b[0],$b[1]]);
        $hash = Data::hash([$actor,$kind,$lines,$reason,$source,$post,$reversal]);
        return $this->db->transaction(function () use ($actor,$kind,$operation,$lines,$reason,$source,$post,$reversal,$hash) {
            $old = $this->db->one('SELECT * FROM lgc_transactions WHERE operation_key=? FOR UPDATE', [$operation]);
            if ($old) { if ($old['payload_hash'] !== $hash) throw new DomainError('operation_conflict',409); return $this->receipt($old); }
            $currencyIds = array_values(array_unique(array_column($lines,0))); sort($currencyIds);
            foreach ($currencyIds as $id) {
                $resource = $this->db->one('SELECT state FROM lgc_currencies WHERE id=? LOCK IN SHARE MODE',[$id]);
                if (!$resource || $resource['state'] !== 'active') throw new DomainError('resource_unavailable',409);
            }
            $balances = [];
            foreach ($lines as [$currency,$user,$delta]) {
                $key = $currency.':'.$user;
                if (isset($balances[$key])) continue;
                $wallet=$this->db->one('SELECT balance FROM lgc_wallets WHERE user_id=? AND currency_id=? FOR UPDATE',[$user,$currency]);
                if (!$wallet) { $this->db->run('INSERT IGNORE INTO lgc_wallets(user_id,currency_id,updated_at) VALUES(?,?,?)',[$user,$currency,Data::now()]); $wallet=$this->db->one('SELECT balance FROM lgc_wallets WHERE user_id=? AND currency_id=? FOR UPDATE',[$user,$currency]); }
                $balances[$key] = (int)$wallet['balance'];
            }
            $uuid = Data::uuid(); $now = Data::now();
            $this->db->run("INSERT INTO lgc_transactions(public_id,operation_key,payload_hash,kind,actor_id,reason,related_post_id,source_key,reversal_of,posted_at) VALUES(?,?,?,?,?,?,?,?,?,?)",[Data::bin($uuid),$operation,$hash,$kind,$actor,$reason,$post,$source,$reversal,$now]);
            $transaction = (int)$this->db->pdo->lastInsertId();
            foreach ($lines as $i => [$currency,$user,$delta]) {
                $key = $currency.':'.$user; $balance = $balances[$key];
                if ($delta < 0 && $balance < -$delta) throw new DomainError('insufficient_balance',409);
                if ($delta > 0 && $balance > PHP_INT_MAX-$delta) throw new DomainError('balance_overflow',409);
                $balances[$key] = $balance+$delta;
                $this->db->run('UPDATE lgc_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency_id=?',[$balances[$key],$now,$user,$currency]);
                $this->db->run('INSERT INTO lgc_entries(transaction_id,line_no,currency_id,user_id,delta,balance_after) VALUES(?,?,?,?,?,?)',[$transaction,$i+1,$currency,$user,$delta,$balances[$key]]);
            }
            $this->outbox->emit('money.posted','transaction:'.$transaction,1,['transaction_id'=>$transaction,'kind'=>$kind,'post_id'=>$post,'user_ids'=>array_values(array_unique(array_map('intval',array_column($lines,1))))],$actor);
            return $this->receipt($this->db->one('SELECT * FROM lgc_transactions WHERE id=?',[$transaction]));
        });
    }
    private function receipt(array $row): array
    {
        $entries = $this->db->all('SELECT e.user_id,e.delta,e.balance_after,c.public_id FROM lgc_entries e JOIN lgc_currencies c ON c.id=e.currency_id WHERE transaction_id=? ORDER BY line_no',[$row['id']]);
        return ['id'=>Data::id($row['public_id']),'internal_id'=>(int)$row['id'],'kind'=>$row['kind'],'entries'=>array_map(fn ($e)=>['user_id'=>(int)$e['user_id'],'currency_id'=>Data::id($e['public_id']),'delta'=>(string)$e['delta'],'balance'=>(string)$e['balance_after']],$entries)];
    }
    public function reverse(Actor $actor, string $transaction): array
    {
        $actor->assert('currency.deduct');
        return $this->db->transaction(function () use ($actor,$transaction) {
            $original = $this->db->one('SELECT * FROM lgc_transactions WHERE public_id=? FOR UPDATE',[Data::bin($transaction)]);
            if (!$original || $original['reversal_of']) throw new DomainError('transaction_not_reversible',409);
            $existing=$this->db->one('SELECT * FROM lgc_transactions WHERE reversal_of=?',[$original['id']]);
            if ($existing) return $this->receipt($existing);
            $entries = $this->db->all('SELECT currency_id,user_id,delta FROM lgc_entries WHERE transaction_id=? ORDER BY line_no',[$original['id']]);
            return $this->post($actor->id,'reversal',hash('sha256','reverse:'.$original['id']),array_map(fn ($e)=>[(int)$e['currency_id'],(int)$e['user_id'],-(int)$e['delta']],$entries),'交易冲正',$original['source_key'],null,(int)$original['id']);
        });
    }
}
