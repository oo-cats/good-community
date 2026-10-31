import { accessText } from '../common/AccessRewardEditor';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Page from 'flarum/common/components/Page';
import UserPage from 'flarum/forum/components/UserPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import AdminPanel from '../common/AdminPanel';
import UserPicker from '../common/UserPicker';
import { input, select, label } from '../common/GoalEditor';
import { api, uuid, errorText, displayDate, rewardText, fieldLabel, resourceIcon } from '../common/api';

let metaPromise;
export function meta() { return metaPromise ||= api(app, '/meta').catch(error => { metaPromise = null; throw error; }); }
export function invalidateMeta() { metaPromise = null; }
function atomText(atom, metadata, task) {
  const p = atom.params; const method = atom.atom_method === 'compare.v1' ? p.left.method : atom.atom_method;
  const name = metadata.methods.find(item => item.method === method)?.title || '条件';
  const params = atom.atom_method === 'compare.v1' ? p.left.params : p;
  const reference = params?.task_id || params?.achievement_id || params?.currency_id;
  const title = reference ? `${name}：${task.reference_names?.[reference] || metadata.resources.find(item => item.id === reference)?.name || '指定对象'}` : name;
  if (atom.atom_method !== 'compare.v1' || metadata.methods.find(item => item.method === method)?.type === 'boolean') return title;
  const op = { '>=': '至少', '>': '多于', '=': '等于', '<=': '至多', '<': '少于', '!=': '不等于' }[p.operator];
  const target = p.right.method ? metadata.methods.find(item => item.method === p.right.method)?.title || '另一个值' : p.right.literal;
  return `${p.left.method ? title : p.left.literal} ${op} ${target}`;
}
function acgnDetails(params, metadata, first = false) {
  const schema = metadata.methods.find(item => item.method === 'acgn.records.count.v1');
  const fields = { ...metadata, system_field_definitions: schema?.query_fields || {} };
  const types = { anime: '动画', manga: '漫画', game: '游戏', novel: '小说' };
  return [m('p.helpText', '作品类型：' + (params.subjects?.types?.map(type => types[type]).join('、') || '不限')),
    params.subjects?.ids?.length > 0 && m('p', ['指定作品：', ...params.subjects.ids.map(id => m(Link, { href: app.forum.attribute('baseUrl') + '/acgn/subjects/' + id }, '作品 #' + id + ' '))]),
    first && m('p.helpText', '只计本人首次标记完成，当前仍完成；导入和重复标记不计新的首次。'),
    (params.where || []).map((terms, index) => m('p.helpText', (index > 0 ? '或者：' : '') + terms.map(term => predicateText(term, fields)).join(' 且 '))),
    params.time && timeDetails(params.time),
    params.related_posts?.required && [m('p', '每部作品须有本人合格文章，且文章只关联该作品；同作品多篇文章只计一部。'), postDetails(params.related_posts.query, metadata, first ? '文章与首次完成采用同一时间范围' : '文章使用全部时间')]];
}
function timeDetails(time, inherited = '跟随本任务当前周期') {
  return m('p.helpText', time.mode === 'fixed' ? '时间：' + displayDate(time.start) + ' 至 ' + displayDate(time.end) : time.mode === 'all' ? '全部时间' : inherited);
}
function postDetails(query, metadata, inherited) {
  const tags = (query.board_tags?.ids || []).map(id => metadata.board_tags?.find(tag => Number(tag.id) === Number(id))?.name || '版面 #' + id);
  return [m('p.helpText', ({ all: '全部帖子', topic: '仅主题首帖', reply: '仅回复' })[query.post_type] || '全部帖子'), timeDetails(query.time || { mode: 'inherit' }, inherited),
    tags.length > 0 && m('p.helpText', '版面：' + tags.join(query.board_tags.match === 'all' ? ' 且 ' : ' 或 ') + (query.board_tags.include_descendants ? '（含子分类）' : '')),
    (query.where || []).map((terms, index) => m('p.helpText', (index > 0 ? '或者：' : '') + terms.map(item => predicateText(item, metadata)).join(' 且 '))),
    query.relations?.acgn && [m('p', '帖子关联的作品须满足：'), acgnDetails(query.relations.acgn, metadata)]];
}
function valueDetails(spec, metadata) {
  if (!spec?.method) return null;
  if (spec.method === 'query.posts.count.v1') return m('details', [m('summary', '查看帖子范围'), postDetails(spec.params.query, metadata)]);
  if (spec.method.startsWith('acgn.')) return m('details', [m('summary', '查看作品和文章条件'), acgnDetails(spec.params, metadata, spec.method === 'acgn.first_completed.count.v1')]);
  return null;
}
function progressText(atom, state, task) {
  const params = atom.params.left;
  if (params?.method === 'tasks.completed_periods.count.v1') return `已完成 ${state.current} / ${state.target} ${task.reference_periods?.[params.params.task_id] === 86400 ? '天' : '个周期'}`;
  return `当前 ${state.current} / 目标 ${state.target}`;
}
function predicateText(item, metadata) {
  const field = metadata.fields.find(field => field.id === item.left.field);
  let value = item.right.literal?.id ?? item.right.literal;
  if (Array.isArray(value)) value = value.map(id => field?.options?.find(option => option.id === id)?.name || id).join('、');
  else if (typeof value === 'boolean') value = value ? '是' : '否';
  else value = field?.options?.find(option => option.id === value)?.name ?? value;
  const definition = metadata.system_field_definitions?.[item.left.field];
  value = definition?.choices?.find(choice => choice[0] === value)?.[1] ?? value;
  if (definition?.type === 'instant' && /^-?\d+$/.test(String(value))) value = new Date(Number(value)).toLocaleString();
  if (item.right.field) value = fieldLabel(metadata, item.right.field);
  const op = { '>=': '≥', '<=': '≤', '!=': '≠', is_set: '已设置', is_unset: '未设置', has_any: '包含任一', has_all: '包含全部' }[item.operator] || item.operator;
  return `${item.negated ? '非：' : ''}${fieldLabel(metadata, item.left.field)} ${op} ${value ?? ''}`;
}
function taskCards(items, metadata, compact = false) {
  return m(`.Community-taskList${compact ? '.Community-taskList--compact' : ''}`, items.map(task => m(Link, { className: 'Community-taskCard', href: app.route('community.task', { id: task.id }) }, [m('.Community-cardHeading', [m('h3', task.name), m('span.Community-status', task.claimed ? '本期已领取' : task.status === 'draft' ? '草稿' : '查看任务')]), task.comment && m('p', task.comment), m('.Community-cardFoot', [m('span', rewardText(task.reward, metadata?.resources)), m('small', task.stop_at ? `截止 ${displayDate(task.stop_at)}` : '无截止时间')])])));
}
export class HomeTasks extends Component {
  oninit(vnode) { super.oninit(vnode); this.items = null; this.alive = true; Promise.all([api(app, '/tasks', 'GET', { limit: 3, pinned: 1 }), meta()]).then(([result, metadata]) => { if (this.alive) { this.items = result.items; this.metadata = metadata; m.redraw(); } }).catch(() => { if (this.alive) this.items = []; }); }
  onremove() { this.alive = false; }
  view() { if (!this.items?.length) return null; return m('section.Community-home', [m('.Community-toolbar', [m('h2', '社区任务'), m(Link, { href: app.route('community.tasks') }, '全部任务 →')]), taskCards(this.items, this.metadata, true)]); }
}
export class TaskListPage extends Page {
  oninit(vnode) { super.oninit(vnode); this.bodyClass = 'App--community'; app.setTitle('社区任务'); this.items = []; this.load(); }
  async load(after) { this.loading = true; try { const [result, metadata] = await Promise.all([api(app, '/tasks', 'GET', { limit: 20, ...(after ? { after } : {}), ...(this.search ? { search: this.search } : {}) }), meta()]); this.items = after ? [...this.items, ...result.items] : result.items; this.next = result.next; this.metadata = metadata; } catch (error) { this.error = errorText(error); } finally { this.loading = false; m.redraw(); } }
  view() { return m('.Community-page.container', [m('.Community-toolbar', [m('div', [m('h1', '社区任务'), m('p.helpText', '达成条件后领取奖励，无需预先接取。')]), app.forum.attribute('communityPermissions')?.tasksManage && m(Link, { href: app.route('community.manage'), className: 'Button' }, '管理任务')]), m('form.Community-row', { onsubmit: event => { event.preventDefault(); this.load(); } }, [input(this.search || '', value => this.search = value, { placeholder: '搜索任务名称', 'aria-label': '搜索任务名称' }), m('button.Button', { type: 'submit' }, '搜索')]), this.error && m('.Community-error', this.error), this.loading && !this.items.length ? m(LoadingIndicator) : this.items.length ? taskCards(this.items, this.metadata) : m('.Community-empty', '当前没有开放的任务。'), this.next && m('button.Button', { onclick: () => this.load(this.next), disabled: this.loading }, '加载更多')]); }
}
export class TaskPage extends Page {
  oninit(vnode) { super.oninit(vnode); this.bodyClass = 'App--community'; this.alive = true; this.load(); }
  onremove() { this.alive = false; clearTimeout(this.timer); }
  async load() {
    try { const [task, metadata, stats] = await Promise.all([api(app, `/tasks/${m.route.param('id')}`), meta(), api(app, `/tasks/${m.route.param('id')}/stats`)]); if (!this.alive) return; this.task = task; this.metadata = metadata; this.stats = stats; app.setTitle(task.name); this.schedule(); }
    catch (error) { if (this.alive) this.error = errorText(error); } finally { if (this.alive) m.redraw(); }
  }
  schedule() { clearTimeout(this.timer); if (this.task?.progress?.state === 'preparing' && (this.polls || 0) < 10) this.timer = setTimeout(() => { if (this.alive && !document.hidden) { this.polls = (this.polls || 0) + 1; this.refresh(); } }, 3000); }
  async refresh() { if (this.task.progress?.state === 'scheduled') return this.load(); try { const response = await api(app, `/tasks/${this.task.id}/check`, 'POST'); if (this.alive) { this.task.progress = response.progress; this.error = ''; this.schedule(); m.redraw(); } } catch (error) { if (this.alive) { this.error = errorText(error); m.redraw(); } } }
  async claim() {
    if (this.busy) return; this.busy = true; this.error = '';
    const task = this.task; const key = `community.intent.${app.session.user.id()}.${task.id}.${task.occurrence}`;
    try {
      let intent;
      try { intent = JSON.parse(localStorage.getItem(key)); } catch (_) {}
      intent ||= { request_id: uuid(), occurrence: task.occurrence, rule_version: task.rule_version };
      // Persist before sending. A timeout or reload must reuse the original payment intent.
      localStorage.setItem(key, JSON.stringify(intent));
      const response = await api(app, `/tasks/${task.id}/claim`, 'POST', intent);
      this.receipt = response.receipt; localStorage.removeItem(key); invalidateMeta(); await this.load();
    } catch (error) { this.error = errorText(error); }
    finally { this.busy = false; if (this.alive) m.redraw(); }
  }
  view() {
    if (!this.task) return m('.Community-page.container', this.error ? m('.Community-error', this.error) : m(LoadingIndicator));
    const task = this.task; const progress = task.progress; const descriptions = { scheduled: '任务尚未开始', pending: '条件尚未达成', claimable: '已达成，可以领取', claimed: '本周期已领取', preparing: '正在准备统计' };
    return m('.Community-page.container', [m(Link, { href: app.route('community.tasks') }, '← 全部任务'), m('header.Community-taskHeader', [m('h1', task.name), m('p', task.comment), m('.Community-meta', [m('span', task.period ? `每${task.period === 86400 ? '天' : task.period === 604800 ? '周' : `${task.period} 秒`}重复` : '一次性任务'), !task.period_start && m('span', `开始：${displayDate(task.start_at)}`), (task.period_end || task.stop_at) && m('span', `本期截止：${displayDate(task.period_end || task.stop_at)}`), this.stats?.completed_users !== null && m('span', `${this.stats.completed_users} 人完成`)])]),
      m('.Community-detailGrid', [m('section.Community-card', [m('h2', '完成条件'), task.goal.map((clause, ci) => m('.Community-conditionClause', [ci > 0 && m('.Community-or', '或者'), m('p.helpText', task.goal.length === 1 ? '同时满足以下条件' : `子式 ${ci + 1}：同时满足以下各项`), clause.map(term => { const atom = task.atoms.find(atom => atom.atom_id === term.atom_id); const state = progress?.atoms?.[term.atom_id]; return m('.Community-condition', [m('span.Community-conditionMark', state ? (term.negated ? !state.truth : state.truth) ? '✓' : '○' : '—'), m('.Community-grow', [m('strong', `${term.negated ? '非：' : ''}${atomText(atom, this.metadata, task)}`), state?.current !== null && state?.current !== undefined && typeof state.current !== 'boolean' && m('small', progressText(atom, state, task)), valueDetails(atom.params.left, this.metadata), atom.params.right?.method && m('div', [m('small', '右侧比较值'), valueDetails(atom.params.right, this.metadata)])])]); })]))]),
        m('aside.Community-card.Community-reward', [m('h2', '领取奖励'), (task.reward.length ? task.reward : [null]).map(item => item ? m('.Community-rewardLine', [m('span', this.metadata.resources.find(resource => resource.id === item.currency_id)?.name || '资源'), m('strong', { className: String(item.delta).startsWith('-') ? 'is-negative' : 'is-positive' }, `${String(item.delta).startsWith('-') ? '' : '+'}${item.delta}`)]) : m('p', '记录任务完成回执')), (task.reward_access || []).map(item => m('p.Community-accessReward', accessText(item, this.metadata))), task.reward.some(item => String(item.delta).startsWith('-')) && m('p.Community-note', '领取时将扣除并销毁上面的负数资源。'), m('p.helpText', progress?.claim_count === '1' ? '本期已领取' : task.period ? '每周期可领取一次' : '本任务可领取一次'), this.receipt && m('.Community-success', { role: 'status' }, '本次领取已完成。'), this.error && m('.Community-error', { role: 'alert' }, this.error), !app.session.user ? m('p', '登录后可以查看个人进度并领取。') : [m('p', { role: 'status' }, descriptions[progress?.state] || '当前不可领取'), m('button.Button.Button--primary', { disabled: !progress?.can_claim || this.busy, onclick: () => this.claim() }, this.busy ? '正在领取…' : this.receipt ? '已领取' : task.reward.length ? '领取奖励' : '完成任务'), progress?.state !== 'claimed' && m('button.Button.Button--text', { disabled: this.busy, onclick: () => { this.polls = 0; this.refresh(); } }, '刷新进度')], task.associate_location && m('p', m(Link, { href: `${app.forum.attribute('baseUrl')}/d/${task.associate_location.discussion_id}/${task.associate_location.number}` }, '查看详细说明帖 →'))]),
      ]),
    ]);
  }
}
export class ManagePage extends Page { oninit(vnode) { super.oninit(vnode); this.bodyClass = 'App--community'; app.setTitle('社区管理'); } view() { return m('.Community-page.container', [m('h1', '社区管理'), m(AdminPanel, { app })]); } }
export class WalletPage extends Page {
  oninit(vnode) { super.oninit(vnode); this.bodyClass = 'App--community'; app.setTitle('我的资源'); this.load(); }
  async load() { try { this.metadata = await api(app, '/meta'); } catch (error) { this.error = errorText(error); } m.redraw(); }
  async transfer() { if (this.busy) return; this.busy = true; try { this.intent ||= uuid(); await api(app, '/money', 'POST', { action: 'transfer', user_id: this.user, currency_id: this.currency, amount: this.amount, reason: this.reason || '', request_id: this.intent }); this.intent = null; this.message = '转账已完成。'; await this.load(); } catch (error) { this.error = errorText(error); } finally { this.busy = false; m.redraw(); } }
  view() { if (!app.session.user) return m('.Community-page.container', '请先登录。'); if (!this.metadata) return m('.Community-page.container', this.error || m(LoadingIndicator)); return m('.Community-page.container', [m('h1', '我的资源'), m('.Community-taskList', this.metadata.resources.map(resource => m('section.Community-card', [m('h2', [resourceIcon(resource), resource.name]), m('strong.Community-balance', resource.balance), m('p.helpText', resource.resource_kind === 'experience' ? '经验成长值 · 不可转账' : '整数货币')]))), this.metadata.permissions['currency.transfer'] && m('section.Community-card', [m('h2', '转账'), m(UserPicker, { app, onchange: value => this.user = value }), label('货币', select(this.currency || '', [['', '选择货币'], ...this.metadata.resources.filter(item => item.resource_kind === 'currency').map(item => [item.id, item.name])], value => this.currency = value, '转账货币')), label('数量', input(this.amount || '', value => this.amount = value, { inputmode: 'numeric' })), label('说明', input(this.reason || '', value => this.reason = value)), this.error && m('.Community-error', this.error), this.message && m('.Community-success', this.message), m('button.Button.Button--primary', { onclick: () => this.transfer(), disabled: this.busy || !this.user || !this.currency }, '确认转账')])]); }
}
export class AchievementsPage extends UserPage {
  oninit(vnode) { super.oninit(vnode); this.loadUser(m.route.param('username')); }
  show(user) { super.show(user); api(app, '/achievements', 'GET', { user_id: user.id() }).then(result => { this.items = result.items; m.redraw(); }).catch(error => { this.error = errorText(error); m.redraw(); }); }
  content() { return m('.Community-profileAchievements', [m('h2', '成就'), this.error ? m('.Community-error', this.error) : !this.items ? m(LoadingIndicator) : !this.items.length ? m('.Community-empty', '尚未获得成就。') : m('.Community-list', this.items.map(item => m('article.Community-listRow', [m('.Community-grow', [m('h3', item.name), m('p', item.description)]), m('small', displayDate(item.earned_at))])))]); }
}
