import { useEffect, useRef, useState } from 'react'
import type { RingMetric, WeekRings } from '@/types/api'

/**
 * Anneaux d'activité de la semaine.
 *
 * Trois anneaux concentriques, à la manière de l'application Forme : distance,
 * temps en mouvement, nombre de sorties. C'est la première chose que voit un
 * membre, et la seule qui doive se lire en une seconde depuis l'autre bout de
 * la pièce.
 *
 * Trois décisions de dessin :
 *
 * 1. **L'anneau ne dépasse jamais un tour.** Le serveur renvoie bien 150 %
 *    quand la semaine a été forte, mais un arc qui repasserait sur lui-même
 *    deviendrait illisible. Le dépassement se lit au chiffre, écrit à côté, et
 *    à l'anneau qui se referme complètement.
 * 2. **Un objectif atteint change d'aspect**, pas seulement de longueur : sans
 *    cela, 98 % et 102 % se ressemblent, alors que l'un est un échec et
 *    l'autre une réussite.
 * 3. **L'animation part de zéro à l'affichage.** Elle ne décore pas : elle
 *    montre le remplissage, ce qui rend la progression sensible. Elle est
 *    neutralisée sous `prefers-reduced-motion`.
 */

interface RingConfig {
  key: keyof WeekRings['metrics']
  label: string
  color: string
  format: (value: number) => string
}

interface ActivityRingsProps {
  rings: WeekRings
  configs: RingConfig[]
  /** Diamètre extérieur, en pixels. */
  size?: number
}

/** Épaisseur d'un anneau et écart entre deux, en pixels pour `size = 200`. */
const STROKE_RATIO = 0.11
const GAP_RATIO = 0.035

export function ActivityRings({ rings, configs, size = 200 }: ActivityRingsProps) {
  const drawn = useAnimatedProgress(rings)

  const stroke = size * STROKE_RATIO
  const gap = size * GAP_RATIO

  return (
    <svg
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      role="img"
      aria-label={describe(rings, configs)}
      className="shrink-0"
    >
      {/* Rotation d'un quart de tour : un anneau qui démarre à 3 h se lit
          comme une horloge décalée. On veut le départ en haut. */}
      <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
        {configs.map((config, index) => {
          const metric = rings.metrics[config.key]
          const radius = size / 2 - stroke / 2 - index * (stroke + gap)
          const circumference = 2 * Math.PI * radius

          // Plafonné à un tour : voir le commentaire de composant.
          const ratio = Math.min(1, (drawn[config.key] ?? 0) / 100)

          return (
            <g key={config.key}>
              {/* Rail : il montre le chemin restant. Sans lui, un anneau à
                  10 % ressemble à une simple virgule sans échelle. */}
              <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke={config.color}
                strokeWidth={stroke}
                opacity={0.16}
              />

              <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke={config.color}
                strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - ratio)}
                style={{
                  transition: 'stroke-dashoffset var(--cd-duration-slow) var(--cd-ease-out)',
                }}
              />

              {/* Objectif atteint : un liseré clair ferme l'anneau. 98 % et
                  102 % doivent se distinguer d'un coup d'œil. */}
              {metric.completed && (
                <circle
                  cx={size / 2}
                  cy={size / 2}
                  r={radius}
                  fill="none"
                  stroke="var(--cd-surface)"
                  strokeWidth={2}
                  opacity={0.9}
                />
              )}
            </g>
          )
        })}
      </g>
    </svg>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Fait partir les anneaux de zéro au premier rendu.
 *
 * On passe par un état plutôt que par une animation CSS pure parce que la
 * valeur de départ dépend des données : `strokeDashoffset` ne peut pas être
 * animé depuis une valeur inconnue à l'écriture de la feuille de style.
 *
 * Un `requestAnimationFrame` suffit à laisser le navigateur peindre l'état
 * initial ; sans lui, React applique les deux valeurs dans la même frame et
 * la transition ne se déclenche pas.
 */
function useAnimatedProgress(rings: WeekRings): Record<string, number> {
  const [drawn, setDrawn] = useState<Record<string, number>>({})
  const frame = useRef<number>(0)

  useEffect(() => {
    const target: Record<string, number> = {}

    for (const [key, metric] of Object.entries(rings.metrics)) {
      target[key] = (metric as RingMetric).percent ?? 0
    }

    frame.current = requestAnimationFrame(() => setDrawn(target))

    return () => cancelAnimationFrame(frame.current)
  }, [rings])

  return drawn
}

/**
 * Description textuelle des anneaux.
 *
 * Un lecteur d'écran ne « voit » pas trois arcs : il doit entendre les trois
 * chiffres et leur objectif. Un `aria-label` générique du type « anneaux
 * d'activité » ne dirait rien de ce qu'ils valent.
 */
function describe(rings: WeekRings, configs: RingConfig[]): string {
  const parts = configs.map((config) => {
    const metric = rings.metrics[config.key]

    if (metric.goal === 0) {
      return `${config.label} : ${config.format(metric.value)}, sans objectif`
    }

    return `${config.label} : ${config.format(metric.value)} sur ${config.format(
      metric.goal,
    )}, ${Math.round(metric.percent ?? 0)} %`
  })

  return `Objectifs de la semaine. ${parts.join('. ')}.`
}
