import pino from 'pino'
import { config } from './config.js'

export const logger = pino({
  level: config.logLevel,
  transport:
    config.env === 'development'
      ? {
          target: 'pino-pretty',
          options: { colorize: true, translateTime: 'HH:MM:ss', ignore: 'pid,hostname' },
        }
      : undefined,
  // Ne jamais laisser un secret ou un jeton atterrir dans les journaux.
  redact: {
    paths: [
      'req.headers.authorization',
      'req.headers["x-cyclo-signature"]',
      'serviceSecret',
      '*.secret',
    ],
    censor: '[masqué]',
  },
})
