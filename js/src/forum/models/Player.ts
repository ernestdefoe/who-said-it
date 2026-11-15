import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

/** A member's all-time record (`who-said-it-players`; the id is the user's). */
export default class Player extends Model {
  totalScore = Model.attribute<number>('totalScore');
  quizzes = Model.attribute<number>('quizzes');
  correctTotal = Model.attribute<number>('correctTotal');
  bestStreak = Model.attribute<number>('bestStreak');
  streak = Model.attribute<number>('streak');
  user = Model.hasOne<User>('user');
}
