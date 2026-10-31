import Component from 'flarum/common/Component';
export default class UserPicker extends Component {
  oninit(vnode) { super.oninit(vnode); this.query = ''; this.results = []; this.ticket = 0; }
  onremove() { clearTimeout(this.timer); this.ticket++; }
  search(value) {
    this.query = value; this.attrs.onchange(null); clearTimeout(this.timer); const ticket = ++this.ticket;
    if (!value.trim()) { this.results = []; return; }
    this.timer = setTimeout(async () => {
      try { const users = await this.attrs.app.store.find('users', { filter: { q: value }, page: { limit: 5 } }); if (ticket === this.ticket) { this.results = users; m.redraw(); } } catch (_) { if (ticket === this.ticket) this.results = []; }
    }, 250);
  }
  view() {
    return m('.Community-userPicker', [m('input.FormControl', { value: this.query, placeholder: '搜索用户名并选择', autocomplete: 'off', 'aria-label': '选择用户', oninput: event => this.search(event.target.value) }), this.results.length > 0 && m('ul.Community-suggestions', this.results.map(user => m('li', m('button.Button.Button--text', { type: 'button', onclick: () => { this.query = user.username(); this.results = []; this.attrs.onchange(Number(user.id())); } }, user.displayName()))))]);
  }
}
