import type { CheckInPayload, Session, SessionUpdatePayload } from '../types/index.ts'
import { del, get, post, put } from './client.ts'

export const fetchActive = () => get<Session | null>('/entries/active')

export const fetchSessions = (limit = 100, offset = 0) =>
	get<Session[]>('/entries', { limit, offset })

export const checkIn = (payload: CheckInPayload) => post<Session>('/entries/check-in', payload)

export const pauseSession = () => post<Session>('/entries/pause')

export const resumeSession = () => post<Session>('/entries/resume')

export const checkOut = () => post<Session>('/entries/check-out')

export const updateSession = (id: number, payload: SessionUpdatePayload) =>
	put<Session>(`/entries/${id}`, payload)

export const deleteSession = (id: number) => del<{ ok: true }>(`/entries/${id}`)
