import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import AdminPanel from '../common/AdminPanel';
class CommunityPage extends ExtensionPage { content() { return m(AdminPanel, { app }); } }
app.initializers.add('local-good-community', () => {
  const extension = app.extensionData.for('local-good-community').registerPage(CommunityPage);
  const permissions = { 'tasks.manage': '管理任务和目录', 'rating.manage': '管理评分范围和权限', 'rating.rate': '为普通帖子评分', 'fields.manage': '管理帖子字段', 'fields.edit': '编辑帖子管理标记', 'currency.viewHistory': '查看其他用户的资源变动明细', 'currency.manage': '管理货币定义与查看总量', 'currency.issue': '系统发行普通货币', 'experience.issue': '系统发放经验', 'currency.deduct': '扣除货币与发布扣除奖励', 'currency.transfer': '使用本人余额转账和打赏', 'achievements.manage': '管理成就定义', 'achievements.grant': '人工授予成就', 'achievements.revoke': '撤销人工成就来源' };
  Object.entries(permissions).forEach(([key, label]) => extension.registerPermission({ icon: 'fas fa-users', label, permission: `local-good-community.${key}` }, 'moderate'));
});
