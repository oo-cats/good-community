export function api(app, path, method = 'GET', data = {}) {
  const query = method === 'GET' && Object.keys(data).length ? `?${new URLSearchParams(data)}` : '';
  return app.request({ method, url: `${app.forum.attribute('apiUrl')}/community${path}${query}`, ...(method === 'GET' ? {} : { body: data }) });
}
export function resourceIcon(resource) {
  const icon = resource?.icon || (resource?.resource_kind === 'experience' ? 'fas fa-star' : 'fas fa-coins');
  return /^(https:\/\/|\/assets\/)/.test(icon) ? m('img.Community-resourceIcon', { src: icon, alt: '', loading: 'lazy' }) : m('i.Community-resourceIcon', { className: icon, 'aria-hidden': true });
}
export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
export function errorText(error) {
  const key = error.response?.error;
  if (key === 'custom_profile_source_unavailable') return '自定义个人资料来源暂不可用，请管理员启用支持发布记录接口的资料插件。';
  return ({ period_window_required: '已完成周期数需要有周期或截止时间的任务窗口。', periodic_task_required: '请选择一个周期任务作为统计对象。', period_window_budget: '统计窗口包含的周期过多，请缩小范围。', name_or_identity_conflict: '名称已存在，请换一个名称。', insufficient_balance: '余额不足，尚未扣除任何资源。', content_changed: '帖子正文已更新，请关闭评分窗口，重新阅读后再评分。', permission_denied: '你没有执行此操作的权限。', not_qualified: '条件尚未达成，请刷新进度。', preparing: '正在准备统计，请稍后刷新。', version_conflict: '内容已被更新，请重新加载后编辑。', rule_version_conflict: '任务版本已更新，请重新打开任务。', outside_task_time: '任务已结束或当前不在领取时间内。', evidence_changed: '帖子条件已变化，请刷新进度后重试。', claim_limit_reached: '本周期领取次数已用完。', repeat_path_needs_progress: '可重复任务的每个子式都需要至少一个可累计进度项。', published_schedule_immutable: '发布后不能更改时间安排，请复制为新任务。', once_goal_frozen: '已开始的一次性任务不能更改条件或奖励，请复制为新任务。', dependency_cycle: '前置任务或成就形成循环，请调整条件。', self_funded_reward: '出资人不能从自己的钱包领取任务奖励。' })[key] || '操作未完成，请检查输入或刷新后重试。';
}
export function localDate(value) {
  if (!value) return '';
  const date = new Date(/^\d+$/.test(String(value)) ? Number(value) : /Z$|[+-]\d\d:\d\d$/.test(value) ? value : value.replace(' ', 'T') + 'Z');
  if (!Number.isFinite(date.getTime())) return '';
  const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return shifted.toISOString().slice(0, 16);
}
export function utcDate(value) { return value ? new Date(value).toISOString() : null; }
export function displayDate(value) { return value ? new Date(value.replace(' ', 'T') + (/Z$/.test(value) ? '' : 'Z')).toLocaleString() : '不限'; }
export function rewardText(reward, resources) { return (reward || []).map(item => `${resources?.find(row => row.id === item.currency_id)?.name || '资源'} ${BigInt(item.delta) > 0n ? '+' : ''}${item.delta}`).join(' · ') || '完成回执'; }
export function fieldLabel(meta, key) {
  return meta.system_field_definitions?.[key]?.title || meta.fields?.find(field => field.id === key)?.name || key;
}
