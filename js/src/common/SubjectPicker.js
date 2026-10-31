import Component from 'flarum/common/Component';

export default class SubjectPicker extends Component {
  oninit(vnode) { super.oninit(vnode); this.results = []; this.names = {}; this.search = ''; this.ticket = 0; }
  onremove() { clearTimeout(this.timer); this.ticket++; }
  find(value) {
    this.search = value; clearTimeout(this.timer); const ticket = ++this.ticket;
    if (!value.trim()) { this.results = []; return; }
    this.timer = setTimeout(async () => {
      try {
        const result = await this.attrs.app.request({ method: 'GET', url: `${this.attrs.app.forum.attribute('apiUrl')}/acgn/subjects?q=${encodeURIComponent(value)}` });
        if (ticket === this.ticket) { this.results = result.items || []; this.error = ''; m.redraw(); }
      } catch (_) { if (ticket === this.ticket) { this.error = '无法搜索作品，请检查 ACGN 插件及查看权限。'; m.redraw(); } }
    }, 250);
  }
  view() {
    const ids = this.attrs.ids;
    return m('.Community-userPicker', [m('input.FormControl', { value: this.search, placeholder: '搜索作品名称；留空表示不限作品', 'aria-label': '搜索作品', oninput: event => this.find(event.target.value) }),
      this.error && m('.helpText', this.error),
      this.results.length > 0 && m('ul.Community-suggestions', this.results.filter(item => !ids.includes(Number(item.id))).map(item => m('li', m('button.Button.Button--text', { type: 'button', onclick: () => { this.names[item.id] = item.title; this.attrs.onchange([...ids, Number(item.id)]); this.results = []; this.search = ''; } }, item.title)))),
      ids.map(id => m('button.Button.Button--text', { type: 'button', onclick: () => this.attrs.onchange(ids.filter(value => value !== id)), 'aria-label': `移除作品 ${this.names[id] || id}` }, `${this.names[id] || `作品 #${id}`} ×`))]);
  }
}
