import { ResourceProfilePage, WalletRedirectPage, AchievementCatalogPage } from './ResourceProfile';
import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Component from 'flarum/common/Component';
import Model from 'flarum/common/Model';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import IndexPage from 'flarum/forum/components/IndexPage';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import UserPage from 'flarum/forum/components/UserPage';
import CommentPost from 'flarum/forum/components/CommentPost';
import PostControls from 'flarum/forum/utils/PostControls';
import Notification from 'flarum/forum/components/Notification';
import NotificationGrid from 'flarum/forum/components/NotificationGrid';
import TextEditor from 'flarum/common/components/TextEditor';
import { api } from '../common/api';
import { TaskListPage, TaskPage, ManagePage, WalletPage, AchievementsPage, HomeTasks, meta } from './Pages';
import { RatingModal, FieldsModal, TipModal, PostTipsModal, InsertTaskModal } from './Modals';

const summaries = new Map(); const queued = new Set(); let summaryTimer;
function queueSummary(id, force = false) {
  if (force) summaries.delete(id);
  if (summaries.has(id)) return;
  queued.add(id); clearTimeout(summaryTimer);
  summaryTimer = setTimeout(async () => {
    const ids = Array.from(queued); queued.clear();
    for (let offset = 0; offset < ids.length; offset += 100) {
      try { const result = await api(app, '/post-summaries', 'GET', { ids: ids.slice(offset, offset + 100).join(',') }); result.items.forEach(item => summaries.set(Number(item.post_id), item)); while (summaries.size > 500) summaries.delete(summaries.keys().next().value); m.redraw(); } catch (error) { console.warn('Community rating summaries unavailable', error.status || error.response?.error || 'request failed'); }
    }
  }, 30);
}
class RatingSummary extends Component {
  oninit(vnode) { super.oninit(vnode); queueSummary(Number(this.attrs.post.id())); }
  view() {
    const post = this.attrs.post; const value = summaries.get(Number(post.id()));
    return m('button.Button.Button--text.Community-ratingSummary', { type: 'button', onclick: () => app.modal.show(RatingModal, { post, saved: () => queueSummary(Number(post.id()), true) }), title: '查看评分或提交自己的评分' }, value?.count ? `评分 ${value.average} · ${value.count} 人${value.rated ? ' · 已评' : ''}` : '评分');
  }
}
class ActivityNotification extends Notification {
  icon() { return 'fas fa-check-circle'; }
  href() { const url = this.attrs.notification.content()?.url || '/community/tasks'; return url.startsWith('/') && !url.startsWith('//') ? app.forum.attribute('baseUrl') + url : app.route('community.tasks'); }
  content() { return this.attrs.notification.content()?.title || '社区动态'; }
  excerpt() { return this.attrs.notification.content()?.reason || null; }
}

const embeds = new Set(); let embedTimer;
async function updateEmbeds() {
  if (document.hidden || !embeds.size) return;
  const definitions = new Map();
  for (const component of Array.from(embeds)) definitions.set(component.key, component.request);
  const requests = Array.from(definitions.values());
  for (let offset = 0; offset < requests.length; offset += 50) {
    try {
      const result = await api(app, '/task-stats/query', 'POST', { items: requests.slice(offset, offset + 50) });
      for (const component of Array.from(embeds)) {
        const item = result.items.find(item => item.task_id === component.request.task_id && (!item.scope || (component.request.scope === 'task' ? item.scope.kind === 'task' : item.scope.kind === 'period' && (component.request.scope === 'current' || String(item.scope.occurrence) === String(component.request.occurrence)))));
        if (item) component.data = item;
      }
      m.redraw();
    } catch (_) {}
  }
}
class TaskEmbed extends Component {
  oninit(vnode) { super.oninit(vnode); this.request = this.attrs.request; this.key = JSON.stringify(this.request); embeds.add(this); clearTimeout(embedTimer); embedTimer = setTimeout(updateEmbeds, 20); if (!TaskEmbed.interval) TaskEmbed.interval = setInterval(updateEmbeds, 30000); }
  onremove() { embeds.delete(this); if (!embeds.size) { clearInterval(TaskEmbed.interval); TaskEmbed.interval = null; } }
  view() {
    const data = this.data;
    if (!data) return m('span.helpText', '正在读取任务信息…');
    if (data.error) return m('span.helpText', '任务信息不可见');
    return m('span.Community-embeddedCard', [m('strong', data.name), m('span', data.completed_users === null ? '统计准备中' : `${data.completed_users} 人完成`), m(Link, { href: app.route('community.task', { id: data.task_id }) }, '查看任务 →')]);
  }
}
function mountEmbeds(component) {
  component.communityEmbeds ||= new Set();
  for (const node of component.element.querySelectorAll('.Community-taskEmbed')) {
    if (component.communityEmbeds.has(node)) continue;
    const request = { task_id: node.dataset.taskId, scope: node.dataset.taskScope || 'task' };
    if (request.scope === 'period') request.occurrence = node.dataset.taskOccurrence || '0';
    component.communityEmbeds.add(node); m.mount(node, { view: () => m(TaskEmbed, { request }) });
  }
  for (const node of component.communityEmbeds) if (!node.isConnected) { m.mount(node, null); component.communityEmbeds.delete(node); }
}

app.initializers.add('local-good-community', () => {
  app.routes['community.tasks'] = { path: '/community/tasks', component: TaskListPage };
  app.routes['community.task'] = { path: '/community/tasks/:id', component: TaskPage };
  app.routes['community.manage'] = { path: '/community/manage', component: ManagePage };
  app.routes['community.wallet'] = { path: '/community/wallet', component: WalletRedirectPage };
  app.routes['community.resources'] = { path: '/u/:username/community-resources', component: ResourceProfilePage };
  app.routes['community.catalog'] = { path: '/community/achievements', component: AchievementCatalogPage };
  app.routes['community.achievements'] = { path: '/u/:username/community-achievements', component: AchievementsPage };
  Promise.resolve().then(() => meta()).then(value => { app.communityMeta = value; m.redraw(); }).catch(() => {});
  extend(IndexPage.prototype, 'navItems', function (items) { items.add('communityCatalog', m(LinkButton, { href: app.route('community.catalog'), icon: 'fas fa-award' }, '社区成就'), 14); items.add('communityTasks', m(LinkButton, { href: app.route('community.tasks'), icon: 'fas fa-tasks' }, '社区任务'), 15); });
  extend(IndexPage.prototype, 'contentItems', function (items) { items.add('communityTasks', m(HomeTasks), 110); });
  extend(SessionDropdown.prototype, 'items', function (items) {
    if (app.forum.attribute('communityPermissions')?.manage) items.add('communityManage', m(LinkButton, { icon: 'fas fa-list', href: app.route('community.manage') }, '任务管理'));
  });
  extend(UserPage.prototype, 'navItems', function (items) { if (this.user) items.add('communityResources', m(LinkButton, { icon: 'fas fa-coins', href: app.route('community.resources', { username: this.user.slug() }) }, '资源')); if (this.user) items.add('communityAchievements', m(LinkButton, { icon: 'fas fa-award', href: app.route('community.achievements', { username: this.user.slug() }) }, '成就')); });
  extend(CommentPost.prototype, 'footerItems', function (items) {
    const tips = summaries.get(Number(this.attrs.post.id()))?.tips || [];
    if (tips.length) items.add('communityTips', m('.Community-tipRecords', [m('strong', '收到打赏'), tips.map(tip => m('p', `${tip.username} 赠予 ${tip.amount} ${tip.currency_name}${tip.reversed ? '（已冲正）' : ''}`)), m('button.Button.Button--text', { onclick: () => app.modal.show(PostTipsModal, { post: this.attrs.post }) }, '查看打赏记录')]), -5); items.add('communityRating', m(RatingSummary, { post: this.attrs.post }), 5); });
  // Flarum retains unchanged post subtrees. External summaries must participate in its cache key.
  extend(CommentPost.prototype, 'oninit', function () { this.subtree.check(() => summaries.get(Number(this.attrs.post.id())), () => app.communityMeta); });
  extend(PostControls, 'moderationControls', function (items, post) { if (app.communityMeta?.permissions['fields.edit'] && post.contentType() === 'comment') items.add('communityFields', m(Button, { icon: 'fas fa-tags', onclick: () => app.modal.show(FieldsModal, { post }) }, '管理标记')); });
  extend(PostControls, 'userControls', function (items, post) { if (app.session.user && app.communityMeta?.permissions['currency.transfer'] && post.user()?.id() !== app.session.user.id()) items.add('communityTip', m(Button, { icon: 'fas fa-gift', onclick: () => app.modal.show(TipModal, { post, saved: () => queueSummary(Number(post.id()), true) }) }, '打赏作者')); });
  extend(TextEditor.prototype, 'toolbarItems', function (items) { items.add('communityTask', m(Button, { className: 'Button Button--icon', icon: 'fas fa-tasks', title: '插入任务信息', onclick: () => app.modal.show(InsertTaskModal, { editor: this.attrs.composer.editor }) })); });
  extend(CommentPost.prototype, 'oncreate', function () { mountEmbeds(this); });
  extend(CommentPost.prototype, 'onupdate', function () { mountEmbeds(this); });
  extend(CommentPost.prototype, 'onremove', function () { for (const node of this.communityEmbeds || []) m.mount(node, null); });
  app.store.models.communityEvents = Model;
  for (const type of ['communityTask', 'communityRating', 'communityMoney']) app.notificationComponents[type] = ActivityNotification;
  extend(NotificationGrid.prototype, 'notificationTypes', function (items) { items.add('communityTask', { name: 'communityTask', icon: 'fas fa-tasks', label: '任务发布与进度通知' }); items.add('communityRating', { name: 'communityRating', icon: 'fas fa-star', label: '评分与正文复核通知' }); items.add('communityMoney', { name: 'communityMoney', icon: 'fas fa-coins', label: '资源到账通知' }); });
}, -10);
