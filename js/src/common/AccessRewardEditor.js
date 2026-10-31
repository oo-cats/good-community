import Component from 'flarum/common/Component';
import { select } from './GoalEditor';
export function permissionName(key) {
  return ({
    viewForum: '浏览论坛', startDiscussion: '发表主题', searchUsers: '搜索用户',
    'discussion.reply': '回复主题', 'discussion.start': '发表主题', 'discussion.likePosts': '点赞帖子',
    'discussion.editPosts': '编辑帖子', 'discussion.hidePosts': '隐藏帖子', 'discussion.hide': '隐藏主题',
    'discussion.rename': '修改主题标题', 'discussion.tag': '调整主题版面', 'discussion.viewIpsPosts': '查看帖子来源地址',
    'user.search': '搜索用户', 'user.viewLastSeenAt': '查看最近在线时间', 'user.edit': '编辑用户资料',
    'local-good-community.rating.rate': '普通帖子评分', 'local-good-community.rating.manage': '管理评分规则',
    'local-good-community.tasks.manage': '管理社区任务', 'local-good-community.fields.edit': '编辑帖子管理标记',
    'local-good-community.fields.manage': '管理帖子字段', 'local-good-community.currency.transfer': '转账与打赏',
    'local-good-community.currency.manage': '管理货币定义', 'local-good-community.currency.issue': '发放货币',
    'local-good-community.currency.deduct': '扣除资源', 'local-good-community.currency.viewHistory': '查看他人资源流水',
    'local-good-community.experience.issue': '发放经验', 'local-good-community.achievements.manage': '管理成就定义',
    'local-good-community.achievements.grant': '授予成就', 'local-good-community.achievements.revoke': '撤销成就',
  })[key] || `扩展权限：${key}`;
}
export function accessText(item, meta) { return item.kind === 'group' ? `加入用户组：${meta.groups?.find(group => Number(group.id) === Number(item.group_id))?.name || '指定用户组'}` : permissionName(item.permission); }
export default class AccessRewardEditor extends Component {
  view() {
    const model = this.attrs.model; const catalog = this.attrs.meta.access_rewards || { groups: [], permissions: [] }; model.reward_access ||= [];
    return m('.Community-accessRewards', [m('h3', '权限奖励'), m('p.helpText', '可加入用户组或授予单项权限，范围不能超过发起人当前拥有的授权。'), model.reward_access.map((item, index) => m('.Community-row', [select(item.kind, [['group', '加入用户组'], ['permission', '授予单项权限']], value => { item.kind = value; delete item.group_id; delete item.permission; }, '权限奖励类型'), item.kind === 'group' ? select(String(item.group_id || ''), [['', '选择用户组'], ...catalog.groups.map(group => [String(group.id), group.name])], value => item.group_id = Number(value), '奖励用户组') : select(item.permission || '', [['', '选择权限'], ...catalog.permissions.map(key => [key, permissionName(key)])], value => item.permission = value, '奖励权限'), m('button.Button.Button--text', { type: 'button', onclick: () => model.reward_access.splice(index, 1) }, '删除权限奖励')])), m('button.Button', { type: 'button', onclick: () => model.reward_access.push({ kind: 'group', group_id: '' }) }, '+ 添加权限奖励')]);
  }
}
