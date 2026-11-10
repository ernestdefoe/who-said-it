import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { t } from '../common/text';

/** "Today's Who Said It? is ready: 5 new quotes." */
export class ReminderNotification extends Notification {
  icon() {
    return 'fas fa-quote-left';
  }

  href() {
    return app.route('who-said-it');
  }

  content(): Mithril.Children {
    return t('forum.notification.reminder', { count: (this.attrs.notification as any).content()?.count || 5 });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}
