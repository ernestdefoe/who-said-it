import Extend from 'flarum/common/extenders';
import { loadPage } from './load';
import { ReminderNotification } from './notifications';
import Player from './models/Player';

export default [
  // The page is a lazy chunk: the route loads it on first visit.
  new Extend.Routes().add('who-said-it', '/who-said-it', loadPage),

  new Extend.Notification().add('whoSaidItReminder', ReminderNotification),

  new Extend.Store().add('who-said-it-players', Player),
];
