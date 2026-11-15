/* RainLoop Webmail (c) RainLoop Team | Licensed under MIT */
import { deleteAsync } from 'del';

import { config } from './config.js';

export const
	remove = (dir) => deleteAsync(dir),
	cleanStatic = () => deleteAsync(config.paths.staticJS) && deleteAsync(config.paths.staticCSS);
