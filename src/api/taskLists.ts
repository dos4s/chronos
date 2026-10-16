import type { TaskList } from '../types/index.ts'

import { get } from './client.ts'

export const fetchTaskLists = () => get<TaskList[]>('/tasklists')
