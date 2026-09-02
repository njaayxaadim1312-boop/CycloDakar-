import { useMutation } from '@tanstack/react-query'
import { Download, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { ApiError, api } from '@/lib/api'
import { useAuth } from '@/stores/auth'

/**
 * Mes données : les emporter, ou les faire effacer.
 *
 * DEUX GESTES QUI N'ONT PAS LE MÊME POIDS, ET QUI NE SE RESSEMBLENT PAS.
 *
 * L'export est un bouton ordinaire. La suppression demande le mot de passe et
 * le mot « SUPPRIMER » tapé en toutes lettres — c'est irréversible, et c'est le
 * seul endroit de l'application où un téléphone laissé déverrouillé sur une
 * table permettrait de détruire un compte en deux appuis.
 *
 * **L'écran dit ce qui NE sera PAS effacé, avant de demander confirmation.**
 * Les écritures comptables engagent la caisse du club et figurent dans des
 * rapports peut-être déjà présentés en assemblée : elles sont anonymisées, pas
 * supprimées. Le découvrir après coup ferait croire à un mensonge ; le dire
 * avant, c'est le respect qu'on doit à quelqu'un qui s'en va.
 */
export function PersonalDataSection() {
  const logout = useAuth((state) => state.logout)

  const [ouvert, setOuvert] = useState(false)
  const [motDePasse, setMotDePasse] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const dialogue = useRef<HTMLDialogElement>(null)

  const exporter = useMutation({
    mutationFn: async () => {
      // La session vit dans un en-tête, pas dans un cookie : un simple lien
      // partirait sans jeton et recevrait 401.
      const reponse = await api.get('/me/export', { responseType: 'blob' })

      const url = URL.createObjectURL(reponse.data as Blob)
      const lien = document.createElement('a')
      lien.href = url
      lien.download = `cyclo-dakar-mes-donnees-${new Date().toISOString().slice(0, 10)}.json`
      document.body.append(lien)
      lien.click()
      lien.remove()
      URL.revokeObjectURL(url)
    },
  })

  const supprimer = useMutation({
    mutationFn: () =>
      api.delete('/me', { data: { password: motDePasse, confirmation } }),
    onSuccess: () => {
      dialogue.current?.close()
      // Le compte n'existe plus : rester connecté n'aurait aucun sens.
      void logout()
    },
  })

  const erreur = supprimer.error instanceof ApiError ? supprimer.error : null

  return (
    <section className="cd-card p-5">
      <h3 className="text-base font-bold">Mes données</h3>
      <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
        Tout ce que le club détient sur vous vous appartient : vous pouvez
        l’emporter, ou demander son effacement.
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => exporter.mutate()}
          disabled={exporter.isPending}
          className="cd-btn cd-btn-ghost"
        >
          <Download size={16} />
          {exporter.isPending ? 'Préparation…' : 'Télécharger mes données'}
        </button>

        <button
          type="button"
          onClick={() => {
            setOuvert(true)
            supprimer.reset()
            dialogue.current?.showModal()
          }}
          className="cd-btn cd-btn-ghost !text-[var(--cd-danger)]"
        >
          <Trash2 size={16} />
          Supprimer mon compte
        </button>
      </div>

      <dialog
        ref={dialogue}
        className="cd-pop m-auto w-[min(92vw,28rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => {
          setOuvert(false)
          setMotDePasse('')
          setConfirmation('')
          supprimer.reset()
        }}
      >
        {ouvert && (
          <form
            method="dialog"
            onSubmit={(event) => {
              event.preventDefault()
              supprimer.mutate()
            }}
            className="max-h-[85dvh] space-y-4 overflow-y-auto p-5"
          >
            <div>
              <h2 className="text-lg font-bold text-[var(--cd-danger)]">
                Supprimer mon compte
              </h2>
              <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
                Cette action est définitive.
              </p>
            </div>

            {/* Dit AVANT la confirmation, pas après. */}
            <div className="space-y-2 rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-bg)] p-3 text-sm">
              <p className="font-semibold">Ce qui sera effacé</p>
              <ul className="list-disc space-y-0.5 pl-5 text-[var(--cd-text-muted)]">
                <li>Vos sorties et leurs traces GPS</li>
                <li>Votre photo et votre fond d’écran</li>
                <li>Votre téléphone, votre email, votre contact d’urgence</li>
                <li>Vos notifications et vos appareils</li>
              </ul>

              <p className="pt-2 font-semibold">Ce qui sera conservé, sans votre nom</p>
              <p className="text-[var(--cd-text-muted)]">
                Les encaissements auxquels vous avez participé. Ils engagent la caisse
                du club et figurent dans des rapports déjà présentés en assemblée :
                les effacer les rendrait faux. Votre nom en est retiré.
              </p>
            </div>

            <label className="block space-y-1">
              <span className="block text-[13px] font-semibold">Votre mot de passe</span>
              <input
                type="password"
                value={motDePasse}
                onChange={(event) => setMotDePasse(event.target.value)}
                required
                autoComplete="current-password"
                className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
              />
            </label>

            <label className="block space-y-1">
              <span className="block text-[13px] font-semibold">
                Tapez SUPPRIMER pour confirmer
              </span>
              <input
                type="text"
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                required
                placeholder="SUPPRIMER"
                className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
              />
              {/* Un bouton se clique par erreur ; un mot se tape volontairement. */}
              <span className="block text-xs text-[var(--cd-text-muted)]">
                En majuscules, exactement.
              </span>
            </label>

            {erreur !== null && (
              <p role="alert" className="text-sm text-[var(--cd-danger)]">
                {erreur.fieldError('confirmation') ??
                  erreur.fieldError('password') ??
                  erreur.message}
              </p>
            )}

            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={() => dialogue.current?.close()}
                className="cd-btn cd-btn-ghost"
              >
                Annuler
              </button>
              <button
                type="submit"
                disabled={supprimer.isPending || confirmation !== 'SUPPRIMER'}
                className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-danger)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              >
                {supprimer.isPending ? 'Suppression…' : 'Supprimer définitivement'}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </section>
  )
}
