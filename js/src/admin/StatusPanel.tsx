import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: import('mithril').Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-who-said-it.admin.status.${key}`, params);

interface Status {
  date: string;
  count: number | null;
  players: number;
  eligible: number;
}

/**
 * Today at a glance: whether the quiz is made, how many have played, and how
 * many posts qualify under the settings below. Never shows the quotes — the
 * admin may want to play too.
 */
export default class StatusPanel extends Component {
  status: Status | null = null;
  busy = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load('GET');
  }

  load(method: 'GET' | 'POST') {
    this.busy = method === 'POST';
    app
      .request<Status>({ method, url: app.forum.attribute<string>('apiUrl') + '/who-said-it/admin' })
      .then((status) => (this.status = status))
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  view() {
    const s = this.status;

    return (
      <div className="Form-group WsiAdminStatus">
        <label>{t('title')}</label>
        {!s ? (
          <LoadingIndicator display="inline" size="small" />
        ) : (
          <div className="WsiAdminStatus-box">
            <p>
              <Icon name={s.count ? 'fas fa-circle-check' : 'far fa-circle'} />{' '}
              {s.count ? t('made', { count: s.count, players: s.players, date: s.date }) : t('not_made', { date: s.date })}
            </p>
            <p className="helpText">{s.eligible >= 1000 ? t('eligible_many') : t('eligible', { count: s.eligible })}</p>
            {!s.count ? (
              <Button className="Button" icon="fas fa-wand-magic-sparkles" loading={this.busy} onclick={() => this.load('POST')}>
                {t('make')}
              </Button>
            ) : null}
            <p className="helpText">{t('scheduler')}</p>
            <p className="helpText">{t('members_only')}</p>
          </div>
        )}
      </div>
    );
  }
}
