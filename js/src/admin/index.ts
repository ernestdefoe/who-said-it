import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/who-said-it', () => {
  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-who-said-it', [
    {
      key: 'whoSaidItToday',
      icon: 'fas fa-quote-left',
      title: () => app.translator.trans('ernestdefoe-who-said-it.admin.widget'),
      placement: 'start_top',
    },
  ]);
});
