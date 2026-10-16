import type { WorkspaceWindowManager } from './windowManager'

let manager: WorkspaceWindowManager | null = null

export function getWindowManager(): WorkspaceWindowManager | null { return manager }
export function setWindowManager(next: WorkspaceWindowManager | null): void { manager = next }
