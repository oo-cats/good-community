import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api, uuid, errorText, displayDate } from '../common/api';
import { input, select, label, literal } from '../common/GoalEditor';
import { meta, invalidateMeta } from './Pages';

export class RatingModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.load(); }
  className() { return 'Community-modal Modal--small'; }
  title() { return '帖子评分'; }
  async load() { try { this.rating = await api(app, `/posts/${this.attrs.post.id()}/rating`); this.score = this.rating.own_score || ''; } catch (error) { this.error = errorText(error); } m.redraw(); }
  async save(withdraw = false) {
    if (this.busy) return; this.busy = true;
    try { this.intent ||= uuid(); await api(app, `/posts/${this.attrs.post.id()}/rating`, 'POST', { action: withdraw ? 'withdraw' : 'rate', request_id: this.intent, expected_version: this.rating.own_version, expected_content_hash: this.attrs.post.attribute('communityContentHash') || this.rating.content_hash, score: this.score, reason: this.reason || '' }); this.attrs.saved?.(); app.modal.close(); }
    catch (error) { this.error = errorText(error); } finally { this.busy = false; m.redraw(); }
  }
  content() { return m('.Modal-body', [this.error && m('.Community-error', { role: 'alert' }, this.error), !this.rating ? m(LoadingIndicator) : [m('p', this.rating.count ? `平均 ${this.rating.average} 分 · ${this.rating.count} 人评分` : '暂未评分'), this.rating.needs_review && m('.Community-note', '帖子正文已修改。原评分保留，你可以复核、改分或撤销。'), this.rating.can_rate ? [label('你的评分', input(this.score, value => this.score = value, { type: 'number', min: 0, max: 100, step: '0.01', inputmode: 'decimal', placeholder: '0—100' })), label('说明（可选）', input(this.reason || '', value => this.reason = value)), m('button.Button.Button--primary', { type: 'button', onclick: () => this.save(), disabled: this.busy || this.score === '' }, '保存评分')] : m('p.helpText', '此帖的评分权限由管理员设置，作者不能给自己评分。'), this.rating.own_score !== null && m('button.Button.Button--text', { type: 'button', onclick: () => this.save(true), disabled: this.busy }, '撤销我的评分')]]); }
}
export class FieldsModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.load(); }
  className() { return 'Community-modal'; }
  title() { return '管理帖子标记'; }
  async load() { try { const [metadata, values] = await Promise.all([meta(), api(app, `/posts/${this.attrs.post.id()}/fields`)]); this.fields = metadata.fields.filter(field => field.state === 'active'); this.values = values.values; this.version = values.version; this.changed = {}; } catch (error) { this.error = errorText(error); } m.redraw(); }
  async save() { if (this.busy) return; this.busy = true; try { await api(app, `/posts/${this.attrs.post.id()}/fields`, 'POST', { expected_version: this.version, values: this.changed }); app.modal.close(); } catch (error) { this.error = errorText(error); } finally { this.busy = false; m.redraw(); } }
  content() { return m('.Modal-body', [this.error && m('.Community-error', this.error), !this.fields ? m(LoadingIndicator) : [m('p.helpText', '只提交你修改的字段；清空表示未设置。'), this.fields.map(field => m('.Community-field', [label(field.name, literal(field.value_type, Object.prototype.hasOwnProperty.call(this.changed, field.id) ? this.changed[field.id] : this.values[field.id], value => this.changed[field.id] = value, field), field.description), m('button.Button.Button--text', { type: 'button', onclick: () => { this.changed[field.id] = null; this.values[field.id] = null; } }, '清空此字段')])), m('button.Button.Button--primary', { type: 'button', onclick: () => this.save(), disabled: this.busy }, '保存标记')]]); }
}
export class TipModal extends Modal {
  oninit(vnode) { super.oninit(vnode); meta().then(value => { this.resources = value.resources.filter(item => item.resource_kind === 'currency'); this.currency = this.resources[0]?.id; m.redraw(); }); }
  className() { return 'Community-modal Modal--small'; }
  title() { return '打赏作者'; }
  async save() { if (this.busy) return; this.busy = true; try { this.intent ||= uuid(); await api(app, `/posts/${this.attrs.post.id()}/tip`, 'POST', { currency_id: this.currency, amount: this.amount, reason: this.reason || '', request_id: this.intent }); invalidateMeta(); this.attrs.saved?.(); app.modal.close(); } catch (error) { this.error = errorText(error); } finally { this.busy = false; m.redraw(); } }
  content() { return m('.Modal-body', [m('p', '从你自己的账户转给帖子作者。'), !this.resources ? m(LoadingIndicator) : [label('货币', select(this.currency || '', this.resources.map(item => [item.id, `${item.name} · 余额 ${item.balance}`]), value => this.currency = value, '打赏货币')), label('数量', input(this.amount || '', value => this.amount = value, { inputmode: 'numeric', placeholder: '正整数' })), label('说明（可选）', input(this.reason || '', value => this.reason = value)), this.error && m('.Community-error', this.error), m('button.Button.Button--primary', { type: 'button', onclick: () => this.save(), disabled: this.busy || !this.currency || !this.amount }, '确认打赏')]]); }
}
export class PostTipsModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.rows = []; this.load(); }
  title() { return '帖子打赏记录'; }
  className() { return 'Community-modal'; }
  async load(before) { try { const result = await api(app, `/posts/${this.attrs.post.id()}/tips`, 'GET', before ? { before } : {}); this.rows = before ? this.rows.concat(result.items) : result.items; this.next = result.next; } catch (error) { this.error = errorText(error); } m.redraw(); }
  content() { return m('.Modal-body', [this.error && m('.Community-error', this.error), m('.Community-tableWrap', m('table.Community-table', [m('thead', m('tr', ['赠予者', '资源', '数量', '时间', '状态'].map(label => m('th', label)))), m('tbody', this.rows.map(row => m('tr', [m('td', row.username), m('td', row.currency_name), m('td', row.amount), m('td', displayDate(row.created_at)), m('td', row.reversed ? '已冲正' : '已到账')])))])), !this.rows.length && m('p.helpText', '暂无打赏。'), this.next && m('button.Button', { onclick: () => this.load(this.next) }, '加载更多')]); }
}
export class InsertTaskModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.scope = 'task'; this.load(); }
  title() { return '插入任务信息'; }
  async load() { try { this.items = (await api(app, '/tasks', 'GET', { limit: 20, ...(this.search ? { search: this.search } : {}) })).items; } catch (error) { this.error = errorText(error); } m.redraw(); }
  content() { return m('.Modal-body', [m('.Community-row', [input(this.search || '', value => this.search = value, { placeholder: '搜索任务名称', 'aria-label': '搜索插入任务' }), m('button.Button', { type: 'button', onclick: () => this.load() }, '搜索')]), select(this.selected || '', [['', '选择任务'], ...(this.items || []).map(item => [item.id, item.name])], value => this.selected = value, '插入任务'), label('统计范围', select(this.scope, [['task', '任务累计'], ['current', '当前周期'], ['period', '指定周期']], value => this.scope = value, '统计范围')), this.scope === 'period' && label('周期编号', input(this.occurrence || '0', value => this.occurrence = value, { inputmode: 'numeric' })), m('p.helpText', '帖子中显示任务名称、完成人数和查看按钮，人数自动更新。'), this.error && m('.Community-error', this.error), m('button.Button.Button--primary', { type: 'button', disabled: !this.selected, onclick: () => { const occurrence = this.scope === 'period' ? ` occurrence="${/^\d{1,10}$/.test(this.occurrence || '0') ? this.occurrence || '0' : '0'}"` : ''; this.attrs.editor.insertAtCursor(`[community-task id="${this.selected}" scope="${this.scope}"${occurrence}]`); app.modal.close(); } }, '插入')]); }
}
