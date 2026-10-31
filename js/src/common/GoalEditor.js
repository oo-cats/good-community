import Component from 'flarum/common/Component';
import SubjectPicker from './SubjectPicker';
import { uuid, localDate, utcDate, fieldLabel } from './api';

export function select(value, options, change, label, disabled = false) {
  return m('select.FormControl', { value, onchange: event => change(event.target.value), 'aria-label': label, disabled }, options.map(([id, name]) => m('option', { key: String(id), value: id }, name)));
}
export function input(value, change, attrs = {}) { return m('input.FormControl', { value: value ?? '', oninput: event => change(event.target.value), ...attrs }); }
export function label(text, control, help) { if (control?.attrs) control.attrs['aria-label'] ||= text; return m('.Form-group', [m('label', [text, control]), help && m('.helpText', help)]); }
export function checks(items, chosen, change, title) {
  return m('.Community-checks', { role: 'group', 'aria-label': title }, items.map(item => m('label.checkbox', [m('input', { type: 'checkbox', checked: chosen.some(id => String(id) === String(item.id)), onchange: event => change(event.target.checked ? [...chosen, item.id] : chosen.filter(id => String(id) !== String(item.id))) }), item.name])));
}
export function literal(type, value, change, field = {}) {
  if (field.choices) return select(value || '', [['', '选择值'], ...field.choices], change, '比较值');
  if (type === 'boolean') return select(String(value ?? true), [['true', '是'], ['false', '否']], value => change(value === 'true'), '布尔值');
  if (type === 'enum') return select(value?.id || value || '', [['', '选择值'], ...(field.options || []).map(option => [option.id, option.name])], change, '枚举值');
  if (type === 'set') return checks(field.options || [], Array.isArray(value) ? value : [], change, '集合值');
  if (type === 'instant') return input(localDate(value), value => change(utcDate(value)), { type: 'datetime-local', 'aria-label': '比较日期' });
  return input(value, change, { type: 'text', inputmode: ['integer', 'duration'].includes(type) ? 'numeric' : type === 'decimal' ? 'decimal' : 'text', 'aria-label': '比较值' });
}
function newTerm() { return { left: { field: 'rating.average' }, operator: '>=', right: { literal: '80' }, negated: false }; }
function newQuery() { return { kind: 'post_count', post_type: 'all', time: { mode: 'inherit', field: 'post.created_at' }, board_tags: { ids: [], match: 'any', include_descendants: false }, admin_tags: { option_ids: [] }, unfiltered: true, where: [[newTerm()]] }; }
export function newAtom(method = 'query.posts.count.v1') {
  return { atom_id: uuid(), atom_method: 'compare.v1', params: { left: { method, params: method === 'query.posts.count.v1' ? { query: newQuery() } : {} }, operator: method === 'query.posts.count.v1' ? '>=' : '=', right: { literal: method === 'query.posts.count.v1' ? '1' : true } } };
}
export function newGoal() { const atom = newAtom(); return { goal: [[{ atom_id: atom.atom_id, negated: false }]], atoms: [atom] }; }

export class QueryEditor extends Component {
  term() { return this.attrs.fields ? { left: { field: this.attrs.fields[0].id }, operator: '=', right: { literal: '' }, negated: false } : newTerm(); }
  oninit(vnode) { super.oninit(vnode); const query = this.attrs.query; query.time ||= { mode: 'inherit', field: 'post.created_at' }; query.board_tags ||= { ids: [], match: 'any', include_descendants: false }; query.admin_tags ||= { option_ids: [] }; if (!query.where?.length) query.where = [[this.term()]]; }
  view() {
    const { query, meta } = this.attrs;
    const fields = this.attrs.fields || [...Object.entries(meta.system_fields || {}).map(([id, type]) => ({ id, name: fieldLabel(meta, id), value_type: type })), ...(meta.fields || []).filter(field => field.rule_enabled && field.state === 'active')];
    return m('.Community-query', [
      !this.attrs.sourceOnly && [m('.Community-row', [label('统计对象', select(query.post_type || 'all', [['all', '全部帖子'], ['topic', '仅主题首帖'], ['reply', '仅回复']], value => query.post_type = value, '统计对象')), label('统计时间', select(query.time.mode, [['inherit', this.attrs.inheritLabel || '跟随任务当前周期'], ...(!this.attrs.periodic ? [['fixed', '手动时间范围']] : [])], value => query.time = { mode: value, field: 'post.created_at', start: null, end: null }, '统计时间'))]),
      this.attrs.periodic && query.time.mode !== 'inherit' && m('.Community-error', ['周期任务必须统计当期的新内容。', m('button.Button', { type: 'button', onclick: () => query.time = { mode: 'inherit' } }, '改为当前周期')]),
      query.time.mode === 'fixed' && m('.Community-row', [label('从', input(localDate(query.time.start), value => query.time.start = utcDate(value), { type: 'datetime-local' })), label('至', input(localDate(query.time.end), value => query.time.end = utcDate(value), { type: 'datetime-local' })), m('.helpText', '空边界表示不限；手动范围独立于任务时间。')]),
      meta.board_tags?.length > 0 && m('details.Community-detail', [m('summary', `版面范围：${query.board_tags.ids.length ? `${query.board_tags.ids.length} 个标签` : '不限'}`), checks(meta.board_tags, query.board_tags.ids.map(String), value => query.board_tags.ids = value.map(Number), '版面标签'), select(query.board_tags.match, [['any', '满足任一标签'], ['all', '满足全部标签']], value => query.board_tags.match = value, '版面匹配方式'), m('label.checkbox', [m('input', { type: 'checkbox', checked: query.board_tags.include_descendants, onchange: event => query.board_tags.include_descendants = event.target.checked }), '包含子分类'])]),
      !this.attrs.disableRelations && m('details.Community-detail', [m('summary', '关联 ACGN 作品'), m('label.checkbox', [m('input', { type: 'checkbox', checked: !!query.relations?.acgn, onchange: event => query.relations = event.target.checked ? { acgn: { subjects: { types: [], ids: [] }, where: [] } } : {} }), '只统计关联指定作品条件的帖子']), query.relations?.acgn && m(AcgnEditor, { params: query.relations.acgn, meta, app: this.attrs.app, schema: meta.methods.find(item => item.method === 'acgn.records.count.v1'), relationOnly: true })]),
      ],
      m('label.checkbox', [m('input', { type: 'checkbox', checked: query.unfiltered, onchange: event => query.unfiltered = event.target.checked }), this.attrs.sourceOnly ? '不限制作品字段' : '不限制帖子字段']),
      !query.unfiltered && m('.Community-filter', [m('p.helpText', '同一子式内同时满足；任一子式满足即可计数。'), query.where.map((clause, ci) => m('.Community-clause', [
        m('.Community-clauseHeading', [m('strong', `子式 ${ci + 1}`), query.where.length > 1 && m('button.Button.Button--text', { type: 'button', onclick: () => query.where.splice(ci, 1), 'aria-label': `删除筛选子式${ci + 1}` }, '删除子式')]),
        clause.map((term, ti) => {
          const field = fields.find(field => field.id === term.left.field) || fields[0]; const type = field?.value_type || 'string';
          const options = ['boolean', 'enum', 'string', 'set'].includes(type) ? [['=', '等于'], ['!=', '不等于'], ['is_set', '已设置'], ['is_unset', '未设置']] : [['>=', '大于或等于'], ['>', '大于'], ['=', '等于'], ['<=', '小于或等于'], ['<', '小于'], ['!=', '不等于'], ['is_set', '已设置'], ['is_unset', '未设置']];
          if (type === 'set') options.splice(0, 2, ['has_any', '包含任一'], ['has_all', '包含全部']);
          return m('.Community-term', [
            select(term.left.field, fields.map(field => [field.id, field.name]), value => { const next = fields.find(field => field.id === value); term.left.field = value; term.operator = next?.value_type === 'set' ? 'has_any' : '='; term.right = { literal: next?.value_type === 'boolean' ? true : next?.value_type === 'set' ? [] : '' }; }, '字段'),
            select(term.operator, options, value => term.operator = value, '比较符'),
            !['is_set', 'is_unset'].includes(term.operator) && [select(term.right.field ? 'field' : 'literal', [['literal', '固定值'], ['field', '另一个字段']], value => term.right = value === 'field' ? { field: term.left.field } : { literal: '' }, '右值类型'), term.right.field ? select(term.right.field, fields.filter(item => item.value_type === type || ['integer', 'decimal'].includes(item.value_type) && ['integer', 'decimal'].includes(type)).map(item => [item.id, item.name]), value => term.right.field = value, '比较字段') : literal(type, term.right.literal, value => term.right.literal = value, field)],
            m('label.checkbox', { title: '仅在该字段已设置时匹配' }, [m('input', { type: 'checkbox', checked: term.negated, onchange: event => term.negated = event.target.checked }), '取反']),
            clause.length > 1 && m('button.Button.Button--text', { type: 'button', onclick: () => clause.splice(ti, 1), 'aria-label': `删除筛选项${ti + 1}` }, '删除项'),
          ]);
        }),
        m('button.Button.Button--text', { type: 'button', onclick: () => clause.push(this.term()) }, '+ 添加项'),
      ])), m('button.Button', { type: 'button', onclick: () => query.where.push([this.term()]) }, '+ 添加子式')]),
    ]);
  }
}

class AcgnEditor extends Component {
  oninit(vnode) { super.oninit(vnode); const params = this.attrs.params; params.subjects ||= { types: [], ids: [] }; this.filter = { unfiltered: !params.where?.length, where: params.where || [] }; }
  view() {
    const { params, schema, meta, app, periodic, relationOnly } = this.attrs;
    const first = schema?.temporal === 'action';
    params.where = this.filter.unfiltered ? [] : this.filter.where;
    if (first) params.time ||= { mode: 'inherit' };
    const fields = Object.entries(schema?.query_fields || {}).map(([id, field]) => ({ id, name: field.title, value_type: field.type, choices: field.choices }));
    return m('.Community-acgn', [
      label('作品类型', checks([{ id: 'anime', name: '动画' }, { id: 'manga', name: '漫画' }, { id: 'game', name: '游戏' }, { id: 'novel', name: '小说' }], params.subjects.types, values => params.subjects.types = values, '作品类型'), '不勾选表示不限类型。'),
      app && m(SubjectPicker, { app, ids: params.subjects.ids, onchange: ids => params.subjects.ids = ids }),
      first && [label('首次完成时间', select(params.time.mode, [['inherit', '跟随任务当前周期'], ...(!periodic ? [['fixed', '手动时间范围'], ['all', '全部时间']] : [])], mode => params.time = { mode }, '首次完成时间')),
        periodic && params.time.mode !== 'inherit' && m('.Community-error', ['周期任务须统计当期首次完成。', m('button.Button', { type: 'button', onclick: () => params.time = { mode: 'inherit' } }, '改为当前周期')]),
        params.time.mode === 'fixed' && m('.Community-row', [label('从', input(localDate(params.time.start), value => params.time.start = utcDate(value), { type: 'datetime-local' })), label('至', input(localDate(params.time.end), value => params.time.end = utcDate(value), { type: 'datetime-local' }))])],
      fields.length > 0 && m(QueryEditor, { query: this.filter, meta, fields, sourceOnly: true }),
      !relationOnly && [m('label.checkbox', [m('input', { type: 'checkbox', checked: !!params.related_posts?.required, onchange: event => { if (event.target.checked) params.related_posts = { required: true, association: 'single_subject', query: { ...newQuery(), post_type: 'topic' } }; else delete params.related_posts; } }), '每部作品还须有本人发表的合格文章']),
        params.related_posts?.required && [m('p.helpText', '按作品去重；文章须只关联这一部作品。多篇文章不会把同一作品重复计数。'), m(QueryEditor, { query: params.related_posts.query, meta, app, periodic: true, disableRelations: true, inheritLabel: first ? '与首次完成使用相同时间范围' : '全部时间（当前状态条件）' })]],
      !first && m('p.helpText', '统计当前状态，导入的收藏也可计入；在周期任务中表示每期保持条件。'),
    ]);
  }
}

export default class GoalEditor extends Component {
  oninit(vnode) { super.oninit(vnode); this.references = { tasks: [], achievements: [] }; this.loadReferences(); }
  async loadReferences() { const { api, app, meta } = this.attrs; this.references.achievements = meta.achievements || []; if (!api || !app) return; try { const tasks = await api(app, '/tasks', 'GET', { manage: 1, limit: 50, ...(this.taskSearch ? { search: this.taskSearch } : {}) }); this.references.tasks = tasks.items; m.redraw(); } catch (_) {} }
  add(clause) { const atom = newAtom(); this.attrs.model.atoms.push(atom); clause.push({ atom_id: atom.atom_id, negated: false }); }
  parameters(spec, model, meta) {
    const method = spec.method; const params = spec.params ||= {}; const schema = meta.methods.find(item => item.method === method);
    return [
      method === 'query.posts.count.v1' && m('details.Community-detail', [m('summary', '筛选哪些帖子'), m(QueryEditor, { query: params.query ||= newQuery(), meta, app: this.attrs.app, periodic: Number(model.period) > 0 })]),
      schema?.editor === 'acgn' && m('.Community-acgnParameters', [m(AcgnEditor, { key: method, params, schema, meta, app: this.attrs.app, periodic: Number(model.period) > 0 })]),
      method === 'achievements.held.v1' && label('指定成就', select(params.achievement_id || '', [['', '选择成就'], ...this.references.achievements.map(item => [item.id, item.name])], value => params.achievement_id = value, '指定成就')),
      ['tasks.completed.v1', 'tasks.completed_periods.count.v1', 'tasks.completed.count.v1'].includes(method) && [
        m('.Community-row', [input(this.taskSearch || '', value => this.taskSearch = value, { placeholder: '按名称搜索任务', 'aria-label': '搜索任务' }), m('button.Button', { type: 'button', onclick: () => this.loadReferences() }, '查找任务')]),
        label('指定任务', select(params.task_id || '', [['', '选择任务'], ...(!this.references.tasks.some(item => item.id === params.task_id) && params.task_id ? [[params.task_id, model.reference_names?.[params.task_id] || '已选择的任务']] : []), ...this.references.tasks.filter(item => method !== 'tasks.completed_periods.count.v1' || item.period > 0).map(item => [item.id, item.name])], value => params.task_id = value, '指定任务')),
        m('.helpText', method === 'tasks.completed.count.v1' ? '跨所有周期累计仍有效的成功领取次数；撤销后扣回，同次重试不重复计数。' : method === 'tasks.completed_periods.count.v1' ? '统计本任务窗口内已领取且仍有效的完整周期；同周期多次领取只计一次。' : '以成功领取为准；周期前置需在本任务周期内重新完成。'),
      ],
      method === 'wallet.balance.v1' && label('资源', select(params.currency_id || '', [['', '选择资源'], ...meta.resources.map(item => [item.id, item.name])], value => params.currency_id = value, '条件资源')),
      method === 'profile.first_avatar_uploaded.v1' && m('.helpText', '首次历史必须可证明；旧用户历史不完整时会显示准备中。'),
      (schema?.fields || []).map(field => label(field.title, literal(field.type, params[field.key], value => params[field.key] = value, field))),
      schema?.help && m('.helpText', schema.help),
    ];
  }
  view() {
    const { model, meta } = this.attrs;
    return m('.Community-goal', [model.goal.map((clause, ci) => m('.Community-clause', [
      ci > 0 && m('.Community-or', '或者'), m('.Community-clauseHeading', [m('strong', `子式 ${ci + 1}`), m('span.helpText', '同时满足以下各项'), model.goal.length > 1 && m('button.Button.Button--text', { type: 'button', onclick: () => model.goal.splice(ci, 1) }, '删除子式')]),
      clause.map((term, ti) => {
        let atom = model.atoms.find(atom => atom.atom_id === term.atom_id);
        if (!atom) return m('.Community-error', '条件定义缺失，请重新加载。');
        if (atom.atom_method !== 'compare.v1') { const previous = atom.atom_method; atom.atom_method = 'compare.v1'; atom.params = { left: { method: previous, params: atom.params }, operator: '=', right: { literal: true } }; }
        const params = atom.params; const method = params.left.method; const schema = meta.methods.find(item => item.method === method); const type = schema?.type || 'boolean';
        return m('.Community-atom', { key: term.atom_id }, [
          m('.Community-term', [m('span.Community-termNumber', `项 ${ti + 1}`), select(method, meta.methods.filter(item => !(item.requires_window && model.mode)).map(item => [item.method, item.title]), value => { const next = newAtom(value); atom.params = next.params; const nextType = meta.methods.find(item => item.method === value)?.type; atom.params.right.literal = nextType === 'boolean' ? true : nextType === 'instant' ? new Date().toISOString() : '1'; }, '原子条件'),
            type !== 'boolean' && [select(params.operator, [['>=', '大于或等于'], ['>', '大于'], ['=', '等于'], ['<=', '小于或等于'], ['<', '小于'], ['!=', '不等于']], value => params.operator = value, '比较符'),
              select(params.right.method ? 'method' : 'literal', [['literal', '固定值'], ['method', '另一个值']], value => params.right = value === 'method' ? { method, params: JSON.parse(JSON.stringify(params.left.params)) } : { literal: '1' }, '比较值来源'),
              params.right.method ? select(params.right.method, meta.methods.filter(item => !(item.requires_window && model.mode) && (item.type === type || ['integer', 'decimal'].includes(type) && ['integer', 'decimal'].includes(item.type))).map(item => [item.method, item.title]), value => params.right = newAtom(value).params.left, '右侧值') : literal(type, params.right.literal, value => params.right.literal = value)],
            m('label.checkbox', [m('input', { type: 'checkbox', checked: term.negated, onchange: event => term.negated = event.target.checked }), '取反']), clause.length > 1 && m('button.Button.Button--text', { type: 'button', onclick: () => clause.splice(ti, 1) }, '删除项')]),
          this.parameters(params.left, model, meta),
          params.right.method && m('details.Community-detail', { open: true }, [m('summary', '右侧值的条件'), this.parameters(params.right, model, meta)]),
        ]);
      }),
      m('button.Button.Button--text', { type: 'button', onclick: () => this.add(clause) }, '+ 添加项'),
    ])), m('button.Button', { type: 'button', onclick: () => { const clause = []; this.add(clause); model.goal.push(clause); } }, '+ 添加子式')]);
  }
}
