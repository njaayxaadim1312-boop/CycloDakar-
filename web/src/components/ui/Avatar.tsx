import clsx from 'clsx'

interface AvatarProps {
  photoUrl?: string | null
  initials: string
  size?: number
  className?: string
}

/**
 * Photo du membre, ou ses initiales à défaut.
 *
 * Beaucoup de fiches n'auront jamais de photo : afficher une silhouette grise
 * générique rendrait une liste de 200 membres illisible, alors que des
 * initiales colorées restent identifiables d'un coup d'œil.
 *
 * La couleur de fond est dérivée des initiales : la même personne garde donc
 * toujours la même couleur, ce qui aide à la repérer dans une liste.
 */
export function Avatar({ photoUrl, initials, size = 40, className }: AvatarProps) {
  if (photoUrl) {
    return (
      <img
        src={photoUrl}
        alt=""
        width={size}
        height={size}
        loading="lazy"
        className={clsx('shrink-0 rounded-full object-cover', className)}
        style={{ width: size, height: size }}
      />
    )
  }

  return (
    <span
      aria-hidden="true"
      className={clsx(
        'inline-flex shrink-0 items-center justify-center rounded-full font-bold',
        className,
      )}
      style={{
        width: size,
        height: size,
        fontSize: Math.round(size * 0.38),
        backgroundColor: colorFor(initials),
        color: '#1a1a1a',
      }}
    >
      {initials}
    </span>
  )
}

/**
 * Palette dérivée de la charte du club (docs/design-system.md), en versions
 * pâles pour rester lisible avec du texte noir dessus.
 */
const PALETTE = [
  '#FFD9A6', // orange pâle
  '#BFD7EA', // bleu pâle
  '#C6EFC6', // vert pâle
  '#E8D5F2', // mauve pâle
  '#FFE0B2',
  '#D3E4CD',
]

function colorFor(initials: string): string {
  let hash = 0
  for (let i = 0; i < initials.length; i++) {
    hash = initials.charCodeAt(i) + ((hash << 5) - hash)
  }
  return PALETTE[Math.abs(hash) % PALETTE.length] ?? PALETTE[0]!
}
