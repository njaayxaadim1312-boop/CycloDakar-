import { useEffect, useRef } from 'react'
import { Animated, Easing, View } from 'react-native'
import Svg, { Circle, G } from 'react-native-svg'
import type { WeekRings } from '../types/api'

const AnimatedCircle = Animated.createAnimatedComponent(Circle)

export interface RingConfig {
  key: keyof WeekRings['metrics']
  label: string
  color: string
}

interface ActivityRingsProps {
  rings: WeekRings
  configs: RingConfig[]
  size?: number
}

/**
 * Anneaux d'activité de la semaine.
 *
 * Miroir de `web/src/components/activity/ActivityRings.tsx`, avec les mêmes
 * règles de dessin :
 *
 * 1. **L'anneau ne dépasse jamais un tour.** Le serveur renvoie bien 150 %
 *    quand la semaine a été forte, mais un arc qui repasserait sur lui-même
 *    deviendrait illisible. Le dépassement se lit au chiffre, à côté.
 * 2. **Un objectif atteint change d'aspect**, pas seulement de longueur : sans
 *    cela, 98 % et 102 % se ressemblent alors que l'un est un échec et l'autre
 *    une réussite.
 * 3. **L'animation part de zéro.** Elle montre le remplissage plutôt que de
 *    décorer.
 *
 * `useNativeDriver` est à `false` : `strokeDashoffset` n'est pas une propriété
 * de transformation, le pilote natif ne sait pas l'animer. Sur trois cercles
 * c'est sans conséquence.
 */
export function ActivityRings({ rings, configs, size = 180 }: ActivityRingsProps) {
  const stroke = size * 0.11
  const gap = size * 0.035

  const progress = useRef(new Animated.Value(0)).current

  useEffect(() => {
    progress.setValue(0)

    Animated.timing(progress, {
      toValue: 1,
      duration: 900,
      easing: Easing.out(Easing.cubic),
      useNativeDriver: false,
    }).start()
  }, [rings, progress])

  return (
    <View accessibilityRole="image" accessibilityLabel={describe(rings, configs)}>
      <Svg width={size} height={size}>
        {/* Départ en haut : un anneau qui démarre à 3 h se lit comme une
            horloge décalée. */}
        <G rotation={-90} originX={size / 2} originY={size / 2}>
          {configs.map((config, index) => {
            const metric = rings.metrics[config.key]
            const radius = size / 2 - stroke / 2 - index * (stroke + gap)
            const circumference = 2 * Math.PI * radius
            const ratio = Math.min(1, (metric.percent ?? 0) / 100)

            return (
              <G key={config.key}>
                {/* Rail : il montre le chemin restant. Sans lui, un anneau à
                    10 % ressemble à une virgule sans échelle. */}
                <Circle
                  cx={size / 2}
                  cy={size / 2}
                  r={radius}
                  fill="none"
                  stroke={config.color}
                  strokeWidth={stroke}
                  strokeOpacity={0.18}
                />

                <AnimatedCircle
                  cx={size / 2}
                  cy={size / 2}
                  r={radius}
                  fill="none"
                  stroke={config.color}
                  strokeWidth={stroke}
                  strokeLinecap="round"
                  strokeDasharray={`${circumference} ${circumference}`}
                  strokeDashoffset={progress.interpolate({
                    inputRange: [0, 1],
                    outputRange: [circumference, circumference * (1 - ratio)],
                  })}
                />

                {/* Objectif atteint : un liseré ferme l'anneau. */}
                {metric.completed && (
                  <Circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="#FFFFFF"
                    strokeWidth={2}
                    strokeOpacity={0.85}
                  />
                )}
              </G>
            )
          })}
        </G>
      </Svg>
    </View>
  )
}

/**
 * Description parlée des anneaux.
 *
 * Un lecteur d'écran ne « voit » pas trois arcs : il doit entendre les trois
 * chiffres et leur objectif.
 */
function describe(rings: WeekRings, configs: RingConfig[]): string {
  const parts = configs.map((config) => {
    const metric = rings.metrics[config.key]

    if (metric.goal === 0) {
      return `${config.label} sans objectif`
    }

    return `${config.label} à ${Math.round(metric.percent ?? 0)} pour cent`
  })

  return `Objectifs de la semaine. ${parts.join('. ')}.`
}
