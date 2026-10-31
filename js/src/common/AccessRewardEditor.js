import Component from 'flarum/common/Component';
import { select } from './GoalEditor';
export function accessText(item, meta) { return `加入用户组：${meta.groups?.find(group => Number(group.id) === Number(item.group_id))?.name || '指定用户组'}`; }
export default class AccessRewardEditor extends Component {
  view() {
    const model = this.attrs.model; const groups = this.attrs.meta.access_rewards?.groups || []; model.reward_access ||= [];
    return m('.Community-accessRewards', [m('h3', '用户组奖励'), m('p.helpText', '只能奖励管理员已授权且发起人有资格授予的用户组。'), model.reward_access.map((item, index) => m('.Community-row', [select(String(item.group_id || ''), [['', '选择用户组'], ...groups.map(group => [String(group.id), group.name])], value => item.group_id = Number(value), '奖励用户组'), m('button.Button.Button--text', { type: 'button', onclick: () => model.reward_access.splice(index, 1) }, '删除')])), m('button.Button', { type: 'button', disabled: !groups.length, onclick: () => model.reward_access.push({ kind: 'group', group_id: '' }) }, '添加用户组奖励'), !groups.length && m('p.helpText', '目前没有可奖励的用户组，请联系管理员配置授权。')]);
  }
}
