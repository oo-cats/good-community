import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Page from 'flarum/common/components/Page';
import UserPage from 'flarum/forum/components/UserPage';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import UserPicker from '../common/UserPicker';
import { input, select, label } from '../common/GoalEditor';
import { api, uuid, errorText, displayDate, resourceIcon } from '../common/api';
const kinds = { tip: '帖子打赏', transfer: '账户转账', issue: '系统发放', deduct: '管理扣除', task_settlement: '任务奖励', reversal: '交易冲正' };
export class ResourcesPanel extends Component {
  oninit(vnode) { super.oninit(vnode); this.rows = []; this.load(); }
  async load(before) {
    const id = this.attrs.user.id(); this.loading = true;
    try {
      if (!before) this.resources = (await api(app, '/resources', 'GET', { user_id: id })).items;
      const own = app.session.user?.id() === id;
      if (!own && !app.communityMeta) app.communityMeta = await api(app, '/meta');
      this.privateHistory = !own && !app.communityMeta?.permissions['currency.viewHistory'];
      if (!this.privateHistory) { const history = await api(app, '/resource-history', 'GET', { user_id: id, ...(before ? { before } : {}) }); this.rows = before ? this.rows.concat(history.items) : history.items; this.next = history.next; }
    } catch (error) { this.error = errorText(error); } finally { this.loading = false; m.redraw(); }
  }
  async transfer() { if (this.busy) return; this.busy = true; try { this.intent ||= uuid(); await api(app, '/money', 'POST', { action: 'transfer', user_id: this.user, currency_id: this.currency, amount: this.amount, reason: this.reason || '', request_id: this.intent }); this.intent = null; this.message = '转账已完成。'; await this.load(); } catch (error) { this.error = errorText(error); } finally { this.busy = false; m.redraw(); } }
  view() {
    if (!this.resources) return this.error ? m('.Community-error', this.error) : m(LoadingIndicator);
    const own = app.session.user?.id() === this.attrs.user.id();
    return m('.Community-profileResources', [m('h2', '资源'), m('.Community-taskList', this.resources.map(resource => m('section.Community-card', [m('h3', [resourceIcon(resource), resource.name]), m('strong.Community-balance', resource.balance), m('p.helpText', resource.resource_kind === 'experience' ? '经验成长值 · 不可转账' : '整数货币')]))), m('h3', '变动记录'), this.privateHistory ? m('p.helpText', '资源明细仅本人和获授权的管理者可见。') : [m('.Community-tableWrap', m('table.Community-table', [m('thead', m('tr', ['时间', '资源', '变化', '变动后余额', '类型', '原因', '操作者', '关联帖子'].map(text => m('th', text)))), m('tbody', this.rows.map(row => m('tr', [m('td', displayDate(row.created_at)), m('td', row.currency_name), m('td', { className: row.delta.startsWith('-') ? 'is-negative' : 'is-positive' }, `${row.delta.startsWith('-') ? '' : '+'}${row.delta}`), m('td', row.balance), m('td', kinds[row.kind] || '资源变动'), m('td', row.reason || kinds[row.kind]), m('td', row.actor), m('td', row.discussion_id ? m(Link, { href: `${app.forum.attribute('baseUrl')}/d/${row.discussion_id}/${row.post_number}` }, `《${row.post_title}》第${row.post_number}楼`) : '—')])))])), !this.rows.length && m('p.helpText', '暂无资源变动。'), this.next && m('button.Button', { disabled: this.loading, onclick: () => this.load(this.next) }, '加载更多记录')], own && app.communityMeta?.permissions['currency.transfer'] && m('details.Community-card', [m('summary', '转账'), m(UserPicker, { app, onchange: value => this.user = value }), label('货币', select(this.currency || '', [['', '选择货币'], ...this.resources.filter(item => item.resource_kind === 'currency').map(item => [item.id, item.name])], value => this.currency = value, '转账货币')), label('数量', input(this.amount || '', value => this.amount = value, { inputmode: 'numeric' })), label('原因', input(this.reason || '', value => this.reason = value)), m('button.Button.Button--primary', { onclick: () => this.transfer(), disabled: this.busy || !this.user || !this.currency }, '确认转账')]), this.error && m('.Community-error', this.error), this.message && m('.Community-success', this.message)]);
  }
}
export class ResourceProfilePage extends UserPage {
  oninit(vnode) { super.oninit(vnode); this.loadUser(m.route.param('username')); }
  content() { return m(ResourcesPanel, { key: this.user.id(), user: this.user }); }
}
export class WalletRedirectPage extends Page {
  oninit(vnode) { super.oninit(vnode); if (app.session.user) m.route.set(app.route('community.resources', { username: app.session.user.slug() })); }
  view() { return m('.container', '请登录后查看自己的资源。'); }
}
export class AchievementCatalogPage extends Page {
  oninit(vnode) { super.oninit(vnode); app.setTitle('社区成就'); this.rows = []; this.load(); }
  async load(after) { this.loading = true; try { const result = await api(app, '/achievement-catalog', 'GET', { ...(after ? { after } : {}), ...(this.search ? { search: this.search } : {}) }); this.rows = after ? this.rows.concat(result.items) : result.items; this.next = result.next; } catch (error) { this.error = errorText(error); } finally { this.loading = false; m.redraw(); } }
  view() { return m('.Community-page.container', [m('h1', '社区成就'), m('p.helpText', '查看成就说明与当前已获得人数。'), m('form.Community-row', { onsubmit: event => { event.preventDefault(); this.load(); } }, [input(this.search || '', value => this.search = value, { placeholder: '按名称搜索成就', 'aria-label': '搜索成就' }), m('button.Button', { type: 'submit' }, '搜索')]), this.error && m('.Community-error', this.error), this.rows.map(item => m('article.Community-listRow', [m('.Community-grow', [m('h2', item.name), m('p', item.description)]), m('strong', `${item.completed_users} 人已获得`), item.earned && m('span.Community-status', '已获得')])), !this.loading && !this.rows.length && m('p', '暂无成就。'), this.next && m('button.Button', { onclick: () => this.load(this.next), disabled: this.loading }, '加载更多')]); }
}
