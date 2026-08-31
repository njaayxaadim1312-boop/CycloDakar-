import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { formatDistance } from '@/lib/format'

interface ElevationProfileProps {
  /** Série réduite par le serveur à ~200 points : `d` en mètres, `a` en mètres. */
  profile: { d: number; a: number }[]
}

/**
 * Profil d'altitude de la sortie.
 *
 * La série est déjà réduite côté serveur : un écran fait 400 pixels de large,
 * transmettre 10 000 valeurs ne changerait rien au dessin et ralentirait
 * l'affichage.
 *
 * L'axe des ordonnées est **cadré sur les valeurs réelles**, pas sur zéro. À
 * Dakar, une sortie oscille entre 5 et 40 m : partir de zéro écraserait le
 * relief en une ligne plate et l'information disparaîtrait.
 */
export function ElevationProfile({ profile }: ElevationProfileProps) {
  if (profile.length < 2) {
    return (
      <p className="py-8 text-center text-sm text-[var(--cd-text-muted)]">
        Aucune donnée d'altitude — l'appareil n'en a pas fourni.
      </p>
    )
  }

  const altitudes = profile.map((point) => point.a)
  const min = Math.min(...altitudes)
  const max = Math.max(...altitudes)
  // Marge de 10 % pour que la courbe ne colle ni au plafond ni au plancher.
  const padding = Math.max(2, Math.round((max - min) * 0.1))

  return (
    <div className="h-48">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={profile} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
          <defs>
            <linearGradient id="elevationFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--cd-orange)" stopOpacity={0.35} />
              <stop offset="100%" stopColor="var(--cd-orange)" stopOpacity={0} />
            </linearGradient>
          </defs>

          <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--cd-border)" />

          <XAxis
            dataKey="d"
            type="number"
            domain={['dataMin', 'dataMax']}
            tickFormatter={(value: number) => formatDistance(value)}
            tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
            axisLine={false}
            tickLine={false}
          />

          <YAxis
            domain={[min - padding, max + padding]}
            tickFormatter={(value: number) => `${Math.round(value)}`}
            tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
            axisLine={false}
            tickLine={false}
            width={44}
          />

          <Tooltip
            contentStyle={{
              backgroundColor: 'var(--cd-surface)',
              border: '1px solid var(--cd-border)',
              borderRadius: 'var(--cd-radius-sm)',
              fontSize: 13,
            }}
            labelFormatter={(value) => `Au km ${(Number(value) / 1000).toFixed(1)}`}
            formatter={(value) => [`${Math.round(Number(value))} m`, 'Altitude']}
          />

          <Area
            type="monotone"
            dataKey="a"
            stroke="var(--cd-orange)"
            strokeWidth={2}
            fill="url(#elevationFill)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}
