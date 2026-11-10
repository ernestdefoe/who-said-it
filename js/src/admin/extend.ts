import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import StatusPanel from './StatusPanel';
import TagPicker from './TagPicker';
import GroupPicker from './GroupPicker';

const t = (key: string) => app.translator.trans(`ernestdefoe-who-said-it.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-who-said-it.${key}`;

function zones(): Record<string, string> {
  let list: string[] = ['UTC'];
  try {
    list = (Intl as any).supportedValuesOf('timeZone');
  } catch {
    // Older browsers: UTC only; the server accepts any IANA zone.
  }
  const options: Record<string, string> = { UTC: 'UTC' };
  for (const zone of list) options[zone] = zone.replace(/_/g, ' ');
  return options;
}

export default [
  new Extend.Admin()
    .customSetting(() => StatusPanel.component(), 200)
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(
      () => ({
        setting: setting('timezone'),
        type: 'select',
        options: zones(),
        default: 'UTC',
        label: t('settings.timezone'),
        help: t('settings.timezone_help'),
      }),
      90
    )
    .setting(
      () => ({ setting: setting('min_age_days'), type: 'number', min: 0, max: 3650, label: t('settings.min_age'), help: t('settings.min_age_help') }),
      80
    )
    .setting(
      () => ({
        setting: setting('min_length'),
        type: 'number',
        min: 20,
        max: 200,
        label: t('settings.min_length'),
        help: t('settings.min_length_help'),
      }),
      70
    )
    .setting(
      () => ({ setting: setting('repeat_days'), type: 'number', min: 0, max: 36500, label: t('settings.repeat'), help: t('settings.repeat_help') }),
      60
    )
    .setting(
      () => ({ setting: setting('active_days'), type: 'number', min: 1, max: 3650, label: t('settings.active'), help: t('settings.active_help') }),
      50
    )
    .customSetting(function (this: any) {
      return TagPicker.component({ stream: this.setting(setting('tags')) });
    }, 40)
    .customSetting(function (this: any) {
      return GroupPicker.component({ stream: this.setting(setting('never_quote_groups')) });
    }, 35)
    .permission(() => ({ permission: 'whoSaidIt.view', icon: 'fas fa-quote-left', label: t('permissions.view'), allowGuest: true }), 'view', 60)
    .permission(() => ({ permission: 'whoSaidIt.play', icon: 'fas fa-quote-left', label: t('permissions.play') }), 'reply', 60),
];
